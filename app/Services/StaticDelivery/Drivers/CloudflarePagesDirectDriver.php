<?php

namespace App\Services\StaticDelivery\Drivers;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Contracts\StaticDeliveryStatusProbeInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use App\Services\StaticDelivery\PagesAssetHash;
use App\Services\StaticDelivery\PagesDeploymentEvidence;
use App\Services\StaticDelivery\SecretReferenceResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Static-only Pages Direct Upload using the existing deployment credential. */
final class CloudflarePagesDirectDriver implements StaticDeliveryDriverInterface, StaticDeliveryStatusProbeInterface
{
    private float $deadline = 0;

    public function __construct(
        private readonly SecretReferenceResolver $secrets,
        private readonly PagesAssetHash $hashes,
        private readonly ExternalPagesSyncDriver $publicVerifier,
        private readonly PagesDeploymentEvidence $deploymentEvidence,
    ) {}

    public function name(): string { return 'cloudflare-pages-direct'; }

    public function deliver(StaticDeliverySnapshot $snapshot, StaticDeliveryBatch $batch): StaticDeliveryResult
    {
        if (config('static-delivery.cloudflare.dry_run')) {
            throw new StaticDeliveryException('DRY_RUN_ONLY', 'No Cloudflare request was made in dry-run mode.');
        }
        $this->deadline = microtime(true) + 100;
        try {
            $result = $this->upload($snapshot, $batch);
            $health = json_decode($snapshot->files['health/delivery.json'] ?? 'null', true);

            return new StaticDeliveryResult(remoteId: $result->remoteId, remoteUrl: $result->remoteUrl,
                confirmedDeployed: false, metadata: $result->metadata + (is_array($health) ? ['snapshot_health' => $health] : []));
        } catch (StaticDeliveryException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never persist raw provider bodies, bearer tokens or request traces.
            throw new StaticDeliveryException('CLOUDFLARE_REQUEST_FAILED', 'Cloudflare delivery could not complete; the bounded outbox retry will handle it.');
        }
    }

    private function upload(StaticDeliverySnapshot $snapshot, StaticDeliveryBatch $batch): StaticDeliveryResult
    {
        $projectPath = $this->projectPath();
        $project = $this->json($this->request()->get($projectPath));
        $branch = (string) config('static-delivery.cloudflare.production_branch', 'main');
        if (($project['production_branch'] ?? null) !== $branch) {
            throw new StaticDeliveryException('PRODUCTION_BRANCH_MISMATCH', 'Configured branch is not the Pages production branch.');
        }
        $identity = 'Horus static manifest '.$snapshot->manifestHash;
        // Reuse a submission accepted before a lost response. Only the latest
        // production deployment is eligible: never reuse an older rollback.
        $deployments = $this->json($this->request()->get($projectPath.'/deployments', ['env' => 'production', 'per_page' => 10]));
        foreach ($deployments as $deployment) {
            if (($deployment['environment'] ?? '') !== 'production') {
                continue;
            }
            if (data_get($deployment, 'deployment_trigger.metadata.commit_message') === $identity
                && ! in_array(data_get($deployment, 'latest_stage.status'), ['failure', 'canceled'], true)) {
                return $this->result($deployment, $snapshot->manifestHash);
            }
            $terminal = in_array(data_get($deployment, 'latest_stage.status'), ['failure', 'canceled'], true)
                || (data_get($deployment, 'latest_stage.name') === 'deploy' && data_get($deployment, 'latest_stage.status') === 'success');
            if (! $terminal) {
                throw new StaticDeliveryException('PAGES_DEPLOYMENT_BUSY', 'Another production deployment is in flight; publication will retry later.');
            }
            break;
        }

        $manifest = [];
        $assets = [];
        foreach ($snapshot->files as $path => $contents) {
            if (in_array($path, ['_headers', '_redirects', '_routes.json'], true)) {
                continue;
            }
            if ($path === '_worker.js' || str_starts_with($path, '_worker.js/') || str_starts_with($path, 'functions/')) {
                throw new StaticDeliveryException('STATIC_ONLY_REQUIRED', 'This driver accepts static snapshots only.');
            }
            $key = 'pages-asset-v1:'.hash('sha256', $contents."\0".pathinfo($path, PATHINFO_EXTENSION));
            $hash = Cache::remember($key, 86400 * 7, fn () => $this->hashes->asset($path, $contents));
            $manifest['/'.$path] = $hash;
            $assets[$hash] = ['path' => $path, 'contents' => $contents];
        }
        $jwt = $this->json($this->request()->get($projectPath.'/upload-token'))['jwt'] ?? '';
        if (! is_string($jwt) || $jwt === '') {
            throw new StaticDeliveryException('UPLOAD_TOKEN_MISSING', 'Cloudflare did not issue an asset upload token.');
        }
        $missing = $this->json($this->request($jwt)->post('pages/assets/check-missing', ['hashes' => array_keys($assets)]));
        $bucket = [];
        $bytes = 0;
        foreach ($missing as $hash) {
            if (! is_string($hash) || ! isset($assets[$hash])) {
                throw new StaticDeliveryException('ASSET_RESPONSE_INVALID', 'Cloudflare returned an unknown asset identifier.');
            }
            $asset = $assets[$hash];
            $encoded = base64_encode($asset['contents']);
            if ($bucket !== [] && ($bytes + strlen($encoded) > 8 * 1024 * 1024 || count($bucket) >= 100)) {
                $this->json($this->request($jwt)->post('pages/assets/upload', $bucket));
                $bucket = [];
                $bytes = 0;
            }
            $bucket[] = ['key' => $hash, 'value' => $encoded, 'base64' => true,
                'metadata' => ['contentType' => $this->contentType($asset['path'])]];
            $bytes += strlen($encoded);
        }
        if ($bucket !== []) {
            $this->json($this->request($jwt)->post('pages/assets/upload', $bucket));
        }
        $this->json($this->request($jwt)->post('pages/assets/upsert-hashes', ['hashes' => array_keys($assets)]));
        $request = $this->request()->asMultipart();
        foreach (['_headers', '_redirects'] as $special) {
            if (isset($snapshot->files[$special])) {
                $request->attach($special, $snapshot->files[$special], $special);
            }
        }
        // _routes.json is meaningful only with a Worker bundle, just as in Wrangler.
        $deployment = $this->json($request->post($projectPath.'/deployments', [
            'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'branch' => $branch,
            'commit_hash' => substr($snapshot->manifestHash, 0, 40),
            'commit_message' => $identity,
        ]));

        return $this->result($deployment, $snapshot->manifestHash);
    }

    public function probe(StaticDeliveryBatch $batch): ?StaticDeliveryResult
    {
        if ($batch->driver && $batch->driver !== $this->name()) {
            $confirmed = $this->publicVerifier->probe($batch);
            if ($confirmed === null && $batch->driver === 'external-pages-sync') {
                // Passive batches have not submitted an upload themselves.
                // Requeue them through normal bounded backoff after switching
                // drivers instead of waiting 30 minutes for a disabled uploader.
                throw new StaticDeliveryException('DELIVERY_DRIVER_CHANGED', 'Unconfirmed passive batch will retry through active server delivery.');
            }

            return $confirmed;
        }
        $id = (string) $batch->remote_deployment_id;
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $id)) {
            return null;
        }
        $this->deadline = microtime(true) + 150;
        try {
            $deployment = $this->json($this->request()->get($this->projectPath().'/deployments/'.$id));
            if (($deployment['environment'] ?? null) !== 'production'
                || data_get($deployment, 'deployment_trigger.metadata.commit_message') !== 'Horus static manifest '.$batch->manifest_hash) {
                throw new StaticDeliveryException('DEPLOYMENT_IDENTITY_MISMATCH', 'Cloudflare deployment does not match the expected production snapshot.');
            }
            $status = data_get($deployment, 'latest_stage.status');
            if (in_array($status, ['failure', 'canceled'], true)) {
                throw new StaticDeliveryException('CLOUDFLARE_DEPLOYMENT_FAILED', 'Cloudflare reported a failed deployment.');
            }
            if (data_get($deployment, 'latest_stage.name') !== 'deploy' || $status !== 'success') {
                return null;
            }
            $public = $this->publicVerifier->verifyPublicArtifacts($batch);
            if ($public === null && ! $this->verifyWafBlockedOrigins($batch, $deployment)) {
                return null;
            }
            $marker = (string) config('static-delivery.external_sync.confirmation_path');
            if ($marker !== '') {
                File::ensureDirectoryExists(dirname($marker), 0700);
                File::replace($marker, $batch->manifest_hash."\n", 0600);
            }

            return $this->result($deployment, $batch->manifest_hash, true, $this->healthEvidence($batch, $deployment));
        } catch (StaticDeliveryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new StaticDeliveryException('CLOUDFLARE_STATUS_UNAVAILABLE', 'Cloudflare deployment status could not be verified.');
        }
    }

    /** Same guarded 403 policy as the established production verification scripts. */
    private function verifyWafBlockedOrigins(StaticDeliveryBatch $batch, array $deployment): bool
    {
        $id = (string) $batch->remote_deployment_id;
        $projectName = (string) config('static-delivery.cloudflare.project');
        $origin = 'https://'.substr($id, 0, 8).'.'.$projectName.'.pages.dev';
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9-]{27}$/', $id)
            || rtrim((string) ($deployment['url'] ?? ''), '/') !== $origin) {
            return false;
        }
        $origins = [
            substr((string) config('static-delivery.external_sync.manifest_url'), 0, -strlen('/delivery-manifest.json')),
            rtrim((string) config('traffic_gate.origin'), '/'),
        ];
        $blocked = [];
        foreach ($origins as $index => $publicOrigin) {
            if (! preg_match('#^https://([A-Za-z0-9.-]+)$#', $publicOrigin, $matches)) {
                return false;
            }
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)
                ->get($publicOrigin.'/delivery-manifest.json', ['expected' => $batch->manifest_hash]);
            if ($response->status() === 403) {
                $blocked[$index] = $matches[1];
            } elseif (! $response->successful() || $response->json('manifestHash') !== $batch->manifest_hash) {
                return false; // Never reinterpret 404, 5xx, redirects or stale 200 as WAF.
            }
        }
        if ($blocked === []) {
            return false;
        }
        $project = $this->json($this->request()->get($this->projectPath()));
        if (data_get($project, 'canonical_deployment.id') !== $id
            || ! $this->deploymentEvidence->verify($batch, $origin)) {
            return false;
        }
        foreach ($blocked as $index => $hostname) {
            $domain = $this->json($this->request()->get($this->projectPath().'/domains/'.rawurlencode($hostname)));
            if (($domain['status'] ?? null) !== 'active' || ($domain['name'] ?? null) !== $hostname) {
                return false;
            }
            $origins[$index] = $origin;
        }

        return $this->publicVerifier->verifyPublicArtifacts($batch, $origins[0], $origins[1]) !== null;
    }

    private function healthEvidence(StaticDeliveryBatch $batch, array $deployment): ?array
    {
        $health = $batch->provider_metadata['snapshot_health'] ?? null;
        if (is_array($health)) {
            return $health;
        }
        // Upgrade an already-uploading batch using its immutable public file,
        // without resubmitting it or rewriting its configuration.
        $id = (string) $batch->remote_deployment_id;
        $origin = 'https://'.substr($id, 0, 8).'.'.config('static-delivery.cloudflare.project').'.pages.dev';
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9-]{27}$/', $id)
            || rtrim((string) ($deployment['url'] ?? ''), '/') !== $origin) {
            return null;
        }
        try {
            $manifest = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)->get($origin.'/delivery-manifest.json');
            $expected = ($manifest->json('files') ?? [])['health/delivery.json'] ?? null;
            if (! $manifest->successful() || $manifest->json('manifestHash') !== $batch->manifest_hash
                || ! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/', $expected)) {
                return null;
            }
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)->get($origin.'/health/delivery.json');

            return $response->successful() && hash_equals($expected, hash('sha256', $response->body()))
                && is_array($response->json()) ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function result(array $deployment, string $hash, bool $confirmed = false, ?array $health = null): StaticDeliveryResult
    {
        $id = (string) ($deployment['id'] ?? '');
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $id)) {
            throw new StaticDeliveryException('DEPLOYMENT_ID_MISSING', 'Cloudflare returned no valid deployment identifier.');
        }

        return new StaticDeliveryResult(remoteId: $id, remoteUrl: null,
            confirmedDeployed: $confirmed, metadata: ['manifest_hash' => $hash] + ($health !== null ? ['snapshot_health' => $health] : []));
    }

    private function request(?string $token = null): PendingRequest
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new StaticDeliveryException('UPLOAD_DEADLINE_EXCEEDED', 'Static upload exceeded its bounded execution window.');
        }

        return Http::baseUrl('https://api.cloudflare.com/client/v4/')
            ->withToken($token ?? $this->secrets->resolve((string) config('static-delivery.cloudflare.api_token_reference')))
            ->withoutRedirecting()->acceptJson()->connectTimeout(min(5, $remaining))->timeout(min(20, $remaining));
    }

    private function projectPath(): string
    {
        $account = (string) config('static-delivery.cloudflare.account_id');
        $project = (string) config('static-delivery.cloudflare.project');
        if (! preg_match('/^[a-f0-9]{32}$/', $account) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,57}$/', $project)) {
            throw new StaticDeliveryException('PAGES_CONFIGURATION_INVALID', 'A valid Cloudflare account and Pages project are required.');
        }

        return "accounts/{$account}/pages/projects/{$project}";
    }

    private function json(Response $response): array
    {
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['success'] ?? false) !== true) {
            throw new StaticDeliveryException('CLOUDFLARE_API_REJECTED', 'Cloudflare request failed with HTTP '.$response->status().'.');
        }

        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }

    private function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'js' => 'application/javascript', 'json' => 'application/json',
            'html' => 'text/html', 'css' => 'text/css', 'svg' => 'image/svg+xml',
            'txt', 'sha256' => 'text/plain', default => 'application/octet-stream',
        };
    }
}
