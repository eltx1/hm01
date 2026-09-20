<?php

namespace App\Services\StaticDelivery;

use App\Models\StaticDeliveryBatch;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Bounded, GET-only evidence; never return credentials, provider bodies or URLs. */
final class StaticDeliveryDiagnostics
{
    public function collect(): array
    {
        $batches = StaticDeliveryBatch::query()->latest('created_at')->limit(3)->get();
        $latest = $batches->first();
        $report = ['batches' => $batches->map(fn ($batch) => $batch->only([
            'id', 'driver', 'status', 'manifest_hash', 'remote_deployment_id',
            'attempts', 'submitted_at', 'deployed_at', 'error_code',
        ]))->all()];
        foreach (['cdn' => config('static-delivery.external_sync.manifest_url'),
            'gate' => rtrim((string) config('traffic_gate.origin'), '/').'/delivery-manifest.json'] as $surface => $url) {
            try {
                $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)->get($url);
                $hash = $response->json('manifestHash');
                $report[$surface] = ['http' => $response->status(), 'manifest_hash' => $this->hash($hash),
                    'matches_latest_batch' => is_string($hash) && $hash === $latest?->manifest_hash];
            } catch (Throwable) {
                $report[$surface] = ['error' => 'PUBLIC_PROBE_UNAVAILABLE'];
            }
        }
        if ($latest?->driver !== 'cloudflare-pages-direct') {
            return $report;
        }
        try {
            $token = app(SecretReferenceResolver::class)->resolve((string) config('static-delivery.cloudflare.api_token_reference'));
            $account = (string) config('static-delivery.cloudflare.account_id');
            $project = (string) config('static-delivery.cloudflare.project');
            if (! preg_match('/^[a-f0-9]{32}$/', $account) || ! preg_match('/^[a-z0-9-]+$/', $project)) {
                throw new \RuntimeException();
            }
            $base = "https://api.cloudflare.com/client/v4/accounts/{$account}/pages/projects/{$project}";
            $request = fn () => Http::withToken($token)->withoutRedirecting()->connectTimeout(3)->timeout(5);
            $projectResponse = $request()->get($base);
            $report['provider_project'] = ['http' => $projectResponse->status(),
                'canonical' => $this->deployment($projectResponse->json('result.canonical_deployment'))];
            $id = (string) $latest->remote_deployment_id;
            if (preg_match('/^[a-zA-Z0-9-]+$/', $id)) {
                $response = $request()->get($base.'/deployments/'.$id);
                $report['provider_batch'] = ['http' => $response->status(),
                    'deployment' => $this->deployment($response->json('result'))];
            }
        } catch (Throwable) {
            $report['provider_error'] = 'PROVIDER_PROBE_UNAVAILABLE';
        }

        return $report;
    }

    private function hash(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) ? $value : null;
    }

    private function deployment(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $message = data_get($value, 'deployment_trigger.metadata.commit_message');
        $hash = is_string($message) && str_starts_with($message, 'Horus static manifest ')
            ? $this->hash(substr($message, strlen('Horus static manifest '))) : null;
        $id = $value['id'] ?? null;
        $stage = data_get($value, 'latest_stage.name');
        $status = data_get($value, 'latest_stage.status');

        return ['id' => is_string($id) && preg_match('/^[a-zA-Z0-9-]+$/', $id) ? $id : null,
            'stage' => in_array($stage, ['queued', 'initialize', 'clone_repo', 'build', 'deploy'], true) ? $stage : null,
            'status' => in_array($status, ['idle', 'active', 'success', 'failure', 'canceled'], true) ? $status : null,
            'production' => ($value['environment'] ?? null) === 'production', 'manifest_hash' => $hash];
    }
}
