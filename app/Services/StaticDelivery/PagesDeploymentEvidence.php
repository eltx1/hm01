<?php

namespace App\Services\StaticDelivery;

use App\Models\StaticDeliveryBatch;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PagesDeploymentEvidence
{
    /** Verify every served file against the trusted batch hash, in bounded chunks. */
    public function verify(StaticDeliveryBatch $batch, string $origin): bool
    {
        $deadline = microtime(true) + 60;
        try {
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)
                ->get($origin.'/delivery-manifest.json');
            $manifest = $response->json();
            if (! $response->successful() || ! is_array($manifest)
                || ($manifest['manifestHash'] ?? null) !== $batch->manifest_hash
                || ! is_array($manifest['files'] ?? null) || $manifest['files'] === []
                || count($manifest['files']) > (int) config('static-delivery.file_budget.hard_limit', 20000)) {
                return false;
            }
            $files = $manifest['files'];
            ksort($files);
            $context = hash_init('sha256');
            foreach ($files as $path => $hash) {
                if (! is_string($path) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                    return false;
                }
                app(StaticPathGuard::class)->path($path);
                hash_update($context, $path."\0".$hash."\n");
            }
            if (! hash_equals((string) $batch->manifest_hash, hash_final($context))) {
                return false;
            }
            // These are Pages configuration inputs, not public files. Gate CSP
            // is independently checked by ExternalPagesSyncDriver afterwards.
            unset($files['_headers'], $files['_redirects'], $files['_routes.json']);
            foreach (array_chunk($files, 16, true) as $chunk) {
                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 1) {
                    return false;
                }
                $pending = [];
                foreach ($chunk as $path => $hash) {
                    $key = 'pages-proof:'.hash('sha256', $origin."\0".$path."\0".$hash);
                    if (Cache::get($key) !== true) {
                        $pending[$path] = ['hash' => $hash, 'key' => $key];
                    }
                }
                $responses = Http::pool(function (Pool $pool) use ($pending, $origin, $remaining): void {
                    foreach ($pending as $path => $file) {
                        $pool->as($path)->withoutRedirecting()->connectTimeout(min(3, $remaining))
                            ->timeout(min(5, $remaining))->get($origin.'/'.$path);
                    }
                }, concurrency: 8);
                foreach ($pending as $path => $file) {
                    $response = $responses[$path] ?? null;
                    if (! $response instanceof Response || ! $response->successful()
                        || ! hash_equals($file['hash'], hash('sha256', $response->body()))) {
                        return false;
                    }
                    // Only immutable deployment URLs qualify. A large snapshot
                    // can resume next scheduler tick without repeating proofs.
                    Cache::put($file['key'], true, 1800);
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
