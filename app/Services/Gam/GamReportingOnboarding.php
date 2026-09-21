<?php

namespace App\Services\Gam;

use App\Enums\GamCredentialType;
use App\Models\GamConnection;
use App\Models\GamCredential;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GamReportingOnboarding
{
    public function __construct(
        private readonly GamManagedCredentials $secrets,
        private readonly GamSoapTransportInterface $transport,
        private readonly AuditRecorder $audit,
        private readonly GamReportingGoogleApp $googleApp,
    ) {}

    public function redirectUri(): string
    {
        return $this->googleApp->redirectUri();
    }

    public function saveApp(array $json, User $actor): void
    {
        $this->googleApp->save($json, $actor);
    }

    public function start(Request $request, Site $site): string
    {
        $input = $request->validate(['ad_unit' => ['nullable', 'string', 'max:255']]);
        $app = $this->googleApp->credentials();
        if ($app === null) {
            $this->fail('Google connection is awaiting platform activation. No technical setup is needed from you. Existing connected accounts remain available.');
        }
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $this->put('state', $state, $this->owner($request, $site) + ['app' => $app, 'verifier' => $verifier, 'redirect_uri' => $this->redirectUri(), 'ad_unit' => trim($input['ad_unit'] ?? '')], 10);
        $this->audit->record('gam.reporting.authorization.started', $site->organization_id, $request->user(), $site);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $app['client_id'], 'redirect_uri' => $this->redirectUri(), 'response_type' => 'code',
            'scope' => config('gam.oauth.scope').' openid email', 'access_type' => 'offline',
            'prompt' => 'consent select_account', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** State is single-use and tied to both the current admin and browser session. */
    public function consumeState(Request $request): array
    {
        $state = (string) $request->query('state', '');
        if (! preg_match('/^[a-f0-9]{64}$/D', $state)) {
            $this->fail('The Google connection request expired. Start again from the website.');
        }

        return Cache::lock($this->key('state-lock', $state), 15)->block(5, function () use ($request, $state): array {
            $data = $this->get('state', $state);
            $this->verifyOwner($request, $data);
            Cache::forget($this->key('state', $state));

            return $data;
        });
    }

    public function finishGoogle(Request $request, array $state, Site $site): string
    {
        if ($request->query('error')) {
            $this->fail('Google authorization was cancelled. You can choose an account and try again.');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 4096) {
            $this->fail('Google did not return a valid authorization. Please try again.');
        }
        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $state['redirect_uri'],
                    'client_id' => $state['app']['client_id'], 'client_secret' => $state['app']['client_secret'],
                    'code_verifier' => $state['verifier'],
                ]);
            $token = $response->json();
            if (! $response->successful() || ! is_array($token) || empty($token['access_token']) || empty($token['refresh_token'])) {
                $this->fail('Google could not enable automatic reporting access. Reconnect and approve access; a refresh token is required.');
            }
            if (! in_array(config('gam.oauth.scope'), explode(' ', $token['scope'] ?? config('gam.oauth.scope')), true)) {
                $this->fail('Ad Manager access was not approved. Reconnect and allow the Ad Manager permission.');
            }
            $identity = Http::withToken($token['access_token'])->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->get('https://openidconnect.googleapis.com/v1/userinfo');
            if (! $identity->successful() || ! is_string($identity->json('sub')) || ! filter_var($identity->json('email'), FILTER_VALIDATE_EMAIL)) {
                $this->fail('Google account identity could not be verified. Reconnect and allow account identification.');
            }
            $material = $state['app'] + ['refresh_token' => $token['refresh_token'], 'token_uri' => 'https://oauth2.googleapis.com/token'];

            return $this->discover($request, $site, $material, GamCredentialType::OAuth2, 'google:'.$identity->json('sub'), $identity->json('email'), $state['ad_unit'] ?? '');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // OAuth exceptions can contain credentials or the authorization code.
            $this->fail('Could not reach Google securely. Please try connecting again.');
        }
    }

    public function serviceAccount(Request $request, Site $site, array $json): string
    {
        if (($json['type'] ?? '') !== 'service_account' || ! is_string($json['client_email'] ?? null)
            || ! preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+\.iam\.gserviceaccount\.com$/D', $json['client_email'])
            || ! is_string($json['private_key'] ?? null) || ! openssl_pkey_get_private($json['private_key'])) {
            $this->fail('Upload the original service-account JSON key downloaded from Google Cloud.');
        }
        $material = array_intersect_key($json, array_flip(['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id']));
        $material['token_uri'] = 'https://oauth2.googleapis.com/token';

        return $this->discover($request, $site, $material, GamCredentialType::ServiceAccount, 'service:'.$json['client_email'], $json['client_email']);
    }

    private function discover(Request $request, Site $site, array $material, GamCredentialType $type, string $identity, string $email, string $adUnit = ''): string
    {
        $connection = new GamConnection(['is_enabled' => true, 'is_reporting_only' => true, 'credential_type' => $type, 'application_name' => config('gam.application_name')]);
        $connection->setRelation('credential', new GamCredential(['credential_type' => $type, 'scopes' => [config('gam.oauth.scope')]]));
        try {
            $transport = $this->transport instanceof GamOfficialSoapTransport
                ? $this->transport->withTimeout(15)->withCredentialMaterial($material) : $this->transport;
            $response = $transport->call($connection, 'NetworkService', 'getAllNetworks');
        } catch (Throwable $exception) {
            $this->audit->record('gam.reporting.discovery.failed', $site->organization_id, $request->user(), $site);
            if (str_contains($exception->getMessage(), 'NETWORK_API_ACCESS_DISABLED')) {
                $this->fail('Enable API access in Ad Manager → Admin → Global settings, then connect again.');
            }
            $this->fail('Google could not list Ad Manager networks. Enable API access and check that this Google user (or service-account email) has access to the network and reports. Then try again.');
        }
        foreach (['rval', 'networks', 'results', 'value'] as $wrapper) {
            if (isset($response[$wrapper]) && is_array($response[$wrapper])) {
                $response = $response[$wrapper];
                break;
            }
        }
        $networks = [];
        foreach (array_is_list($response) ? $response : [$response] as $network) {
            if (! is_array($network) || ! preg_match('/^\d{1,64}$/D', (string) ($network['networkCode'] ?? ''))) {
                continue;
            }
            $networks[(string) $network['networkCode']] = [
                'code' => (string) $network['networkCode'], 'name' => (string) ($network['displayName'] ?? $network['networkCode']),
                'currency' => $network['currencyCode'] ?? null, 'timezone' => $network['timeZone'] ?? null,
            ];
        }
        if ($networks === []) {
            $this->fail('No Ad Manager networks are accessible. Use a Google account with network access, or add the service-account email under Ad Manager users and enable API access.');
        }
        $flow = (string) Str::ulid();
        $this->put('pending', $flow, $this->owner($request, $site) + [
            'material' => $material, 'type' => $type->value, 'identity' => $identity, 'email' => $email, 'networks' => $networks, 'ad_unit' => $adUnit,
        ], 20);
        $this->audit->record('gam.reporting.networks.discovered', $site->organization_id, $request->user(), $site, newValues: ['count' => count($networks)]);

        return $flow;
    }

    public function pending(Request $request, Site $site, string $flow): array
    {
        if (! Str::isUlid($flow)) {
            $this->fail('This connection session expired. Connect the account again.');
        }
        $data = $this->get('pending', $flow);
        $this->verifyOwner($request, $data, $site);

        return $data;
    }

    /** A completed flow returns its original result on a retry instead of duplicating accounts. */
    public function connect(Request $request, Site $site, string $flow, array $codes): array
    {
        return Cache::lock($this->key('connect-lock', $flow), 30)->block(5, function () use ($request, $site, $flow, $codes): array {
            $data = $this->pending($request, $site, $flow);
            if (isset($data['connected_ids'])) {
                return $data['connected_ids'];
            }
            $codes = array_values(array_unique($codes));
            if (($data['ad_unit'] ?? '') !== '' && count($codes) !== 1) {
                $this->fail('Choose the network containing this website’s ad unit.');
            }
            if ($codes === [] || count($codes) > 25 || array_diff($codes, array_map('strval', array_keys($data['networks'])))) {
                $this->fail('Select the Ad Manager networks returned by Google for this account.');
            }
            $ids = [];
            foreach ($codes as $code) {
                $key = hash('sha256', $request->user()->organization_id.'|'.$data['identity'].'|'.$code);
                $ids[] = Cache::lock('gam:report-account:'.$key, 30)->block(5, function () use ($request, $data, $code, $key): string {
                    return DB::transaction(function () use ($request, $data, $code, $key): string {
                        $connection = GamConnection::withoutGlobalScopes()->where('reporting_account_key', $key)->lockForUpdate()->first();
                        $id = $connection?->id ?? strtolower((string) Str::ulid());
                        $reference = $this->secrets->write($data['material'], $id);
                        $values = [
                            'organization_id' => $request->user()->organization_id, 'name' => Str::limit($data['networks'][$code]['name'].' · '.$data['email'], 255, ''),
                            'type' => 'HORUS_GAM', 'credential_type' => $data['type'], 'driver' => 'HYBRID', 'network_code' => $code,
                            'application_name' => config('gam.application_name'), 'is_reporting_only' => true, 'reporting_account_key' => $key,
                            'is_primary' => false, 'is_enabled' => true, 'dry_run_default' => true, 'health_status' => 'HEALTHY',
                            'last_health_check_at' => now(), 'updated_by' => $request->user()->id,
                        ];
                        if ($connection) {
                            $oldCredential = $connection->credential;
                            Cache::forget('gam:oauth:'.hash('sha256', $id.'|'.($oldCredential?->rotated_at?->timestamp ?? '0')));
                            $connection->update($values);
                        } else {
                            $connection = new GamConnection($values + ['created_by' => $request->user()->id]);
                            $connection->id = $id;
                            $connection->save();
                        }
                        $connection->credential()->updateOrCreate(['gam_connection_id' => $id], [
                            'organization_id' => $connection->organization_id, 'credential_type' => $data['type'], 'reference' => $reference,
                            'client_email_hint' => $data['email'], 'oauth_client_id_hint' => $data['material']['client_id'] ?? null,
                            'scopes' => [config('gam.oauth.scope')], 'rotated_at' => now(),
                        ]);
                        $connection->networks()->updateOrCreate(['network_code' => $code], [
                            'organization_id' => $connection->organization_id, 'display_name' => $data['networks'][$code]['name'],
                            'currency_code' => $data['networks'][$code]['currency'], 'time_zone' => $data['networks'][$code]['timezone'],
                            'is_current' => true, 'last_seen_at' => now(),
                        ]);
                        $this->audit->record('gam.reporting.account.connected', $connection->organization_id, $request->user(), $connection,
                            newValues: ['network_code' => $code, 'is_reporting_only' => true]);

                        return $id;
                    });
                });
            }
            // Discard temporary secrets as soon as the selected accounts are saved.
            unset($data['material']);
            $data['connected_ids'] = $ids;
            $this->put('pending', $flow, $data, 20);

            return $ids;
        });
    }

    private function owner(Request $request, Site $site): array
    {
        if (! $request->session()->has('gam_onboarding_browser')) {
            $request->session()->put('gam_onboarding_browser', bin2hex(random_bytes(32)));
        }

        return ['user_id' => $request->user()->id, 'site_id' => $site->id, 'browser' => hash('sha256', $request->session()->get('gam_onboarding_browser'))];
    }

    private function verifyOwner(Request $request, array $data, ?Site $site = null): void
    {
        if (($data['user_id'] ?? null) !== $request->user()->id || ($site && ($data['site_id'] ?? null) !== $site->id)
            || ! hash_equals($data['browser'] ?? '', hash('sha256', (string) $request->session()->get('gam_onboarding_browser', '')))) {
            $this->fail('This connection request belongs to another session or has expired. Start again from the website.');
        }
    }

    private function put(string $kind, string $id, array $data, int $minutes): void
    {
        Cache::put($this->key($kind, $id), Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), now()->addMinutes($minutes));
    }

    private function get(string $kind, string $id): array
    {
        $stored = Cache::get($this->key($kind, $id));
        if (! is_string($stored)) {
            $this->fail('This connection session expired. Connect the account again.');
        }

        return json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
    }

    private function key(string $kind, string $id): string
    {
        return 'gam:onboarding:'.$kind.':'.hash('sha256', $id);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['gam_account' => $message]);
    }
}
