<?php

namespace App\Services\StaticDelivery\Drivers;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Contracts\StaticDeliveryStatusProbeInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use App\Services\StaticDelivery\SecretReferenceResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class CloudflarePagesPipelineDriver implements StaticDeliveryDriverInterface, StaticDeliveryStatusProbeInterface
{
    public function __construct(private readonly SecretReferenceResolver $secrets) {}

    public function name(): string { return 'cloudflare-pages-pipeline'; }

    public function deliver(StaticDeliverySnapshot $snapshot, StaticDeliveryBatch $batch): StaticDeliveryResult
    {
        if ((bool) config('static-delivery.cloudflare.dry_run', false)) {
            throw new StaticDeliveryException('DRY_RUN_ONLY', 'Cloudflare Pages pipeline is configured for dry-run; no remote write was attempted.');
        }

        $repository = $this->repository();
        $branch = (string) config('static-delivery.cloudflare.delivery_branch', 'edge-delivery');
        if (! preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
            throw new StaticDeliveryException('DELIVERY_BRANCH_INVALID', 'Configured delivery branch is invalid.');
        }
        $request = $this->request();
        $refResponse = $request->get("repos/{$repository}/git/ref/heads/{$branch}");
        $parent = '';
        $baseTree = '';
        $currentTree = ['tree' => []];
        if ($refResponse->successful()) {
            $ref = $this->json($refResponse, 'DELIVERY_BRANCH_UNAVAILABLE');
            $parent = (string) data_get($ref, 'object.sha');
            $commit = $this->json($request->get("repos/{$repository}/git/commits/{$parent}"), 'DELIVERY_COMMIT_UNAVAILABLE');
            $baseTree = (string) data_get($commit, 'tree.sha');
            if ($parent === '' || $baseTree === '') {
                throw new StaticDeliveryException('DELIVERY_BRANCH_INVALID', 'Delivery branch response did not contain required commit metadata.');
            }
            $currentTree = $this->json($request->get("repos/{$repository}/git/trees/{$baseTree}", ['recursive' => 1]), 'DELIVERY_TREE_UNAVAILABLE');
        } elseif ($refResponse->status() !== 404) {
            $this->json($refResponse, 'DELIVERY_BRANCH_UNAVAILABLE');
        }

        $entries = [];
        $currentFiles = [];
        foreach ((array) ($currentTree['tree'] ?? []) as $entry) {
            $path = (string) ($entry['path'] ?? '');
            if (($entry['type'] ?? null) !== 'blob') {
                continue;
            }
            $currentFiles[$path] = (string) ($entry['sha'] ?? '');
            if ($this->managedPath($path) && ! array_key_exists($path, $snapshot->files)) {
                $entries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
            }
        }
        foreach ($snapshot->files as $path => $contents) {
            $expectedGitSha = sha1('blob '.strlen($contents)."\0".$contents);
            if (($currentFiles[$path] ?? null) === $expectedGitSha) {
                continue;
            }
            $blob = $this->json($request->post("repos/{$repository}/git/blobs", [
                'content' => base64_encode($contents),
                'encoding' => 'base64',
            ]), 'BLOB_UPLOAD_FAILED');
            $entries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => (string) ($blob['sha'] ?? '')];
        }

        if ($entries === [] && $parent !== '') {
            $commitSha = $parent;
        } else {
            $treePayload = ['tree' => $entries];
            if ($baseTree !== '') {
                $treePayload['base_tree'] = $baseTree;
            }
            $tree = $this->json($request->post("repos/{$repository}/git/trees", $treePayload), 'TREE_CREATE_FAILED');
            $commitPayload = [
            'message' => "Deploy static edge batch {$batch->id} ({$snapshot->manifestHash})",
            'tree' => (string) ($tree['sha'] ?? ''),
            ];
            if ($parent !== '') {
                $commitPayload['parents'] = [$parent];
            }
            $newCommit = $this->json($request->post("repos/{$repository}/git/commits", $commitPayload), 'COMMIT_CREATE_FAILED');
            $commitSha = (string) ($newCommit['sha'] ?? '');
            if ($parent === '') {
                $this->json($request->post("repos/{$repository}/git/refs", [
                    'ref' => "refs/heads/{$branch}", 'sha' => $commitSha,
                ]), 'BRANCH_CREATE_FAILED');
            } else {
                $this->json($request->patch("repos/{$repository}/git/refs/heads/{$branch}", [
                    'sha' => $commitSha, 'force' => false,
                ]), 'BRANCH_UPDATE_FAILED');
            }
        }

        $dispatch = $request->post("repos/{$repository}/dispatches", [
            'event_type' => 'cloudflare-pages-static-delivery',
            'client_payload' => [
                'batch_id' => $batch->id,
                'delivery_commit' => $commitSha,
                'manifest_hash' => $snapshot->manifestHash,
            ],
        ]);
        if (! $dispatch->successful()) {
            throw new StaticDeliveryException('WORKFLOW_DISPATCH_FAILED', 'GitHub accepted the delivery commit but rejected the deployment workflow dispatch.');
        }

        return new StaticDeliveryResult(
            remoteId: $commitSha,
            remoteUrl: "https://github.com/{$repository}/commit/{$commitSha}",
            confirmedDeployed: false,
            metadata: ['delivery_commit' => $commitSha, 'manifest_hash' => $snapshot->manifestHash],
        );
    }

    public function probe(StaticDeliveryBatch $batch): ?StaticDeliveryResult
    {
        $repository = $this->repository();
        $runs = $this->json($this->request()->get("repos/{$repository}/actions/workflows/cloudflare-pages-delivery.yml/runs", [
            'event' => 'repository_dispatch', 'per_page' => 50,
        ]), 'WORKFLOW_STATUS_UNAVAILABLE');
        foreach ((array) ($runs['workflow_runs'] ?? []) as $run) {
            if (! str_contains((string) ($run['display_title'] ?? ''), $batch->id)) {
                continue;
            }
            if (($run['status'] ?? null) !== 'completed') {
                return null;
            }
            if (($run['conclusion'] ?? null) !== 'success') {
                throw new StaticDeliveryException('CLOUDFLARE_DEPLOYMENT_FAILED', 'Cloudflare Pages deployment workflow completed without success.');
            }
            $jobs = $this->json($this->request()->get("repos/{$repository}/actions/runs/{$run['id']}/jobs"), 'WORKFLOW_JOBS_UNAVAILABLE');
            $deployStep = null;
            foreach ((array) ($jobs['jobs'] ?? []) as $job) {
                foreach ((array) ($job['steps'] ?? []) as $step) {
                    if (($step['name'] ?? null) === 'Deploy static project to Cloudflare Pages') {
                        $deployStep = $step;
                    }
                }
            }
            if (($deployStep['conclusion'] ?? null) !== 'success') {
                throw new StaticDeliveryException(
                    ($deployStep['conclusion'] ?? null) === 'skipped' ? 'CLOUDFLARE_CREDENTIALS_MISSING' : 'CLOUDFLARE_DEPLOYMENT_UNCONFIRMED',
                    'Workflow validation passed, but a successful live Cloudflare Pages deploy step was not found.',
                );
            }

            return new StaticDeliveryResult(
                remoteId: (string) ($run['id'] ?? $batch->remote_deployment_id),
                remoteUrl: isset($run['html_url']) ? (string) $run['html_url'] : null,
                confirmedDeployed: true,
                metadata: array_filter([
                    'delivery_commit' => data_get($batch->provider_metadata, 'delivery_commit'),
                    'workflow_run_id' => $run['id'] ?? null,
                    'manifest_hash' => $batch->manifest_hash,
                ]),
            );
        }

        return null;
    }

    private function request(): PendingRequest
    {
        $token = $this->secrets->resolve((string) config('static-delivery.cloudflare.github_token_reference'));

        return Http::baseUrl('https://api.github.com/')
            ->withToken($token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->connectTimeout((int) config('static-delivery.cloudflare.connect_timeout', 5))
            ->timeout((int) config('static-delivery.cloudflare.timeout', 20));
    }

    /** @return array<string, mixed> */
    private function json(Response $response, string $category): array
    {
        try {
            if (! $response->successful()) {
                throw new StaticDeliveryException($category, 'Static delivery provider returned HTTP '.$response->status().'.');
            }
            $json = $response->json();

            return is_array($json) ? $json : [];
        } catch (StaticDeliveryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new StaticDeliveryException($category, 'Static delivery provider response could not be processed.');
        }
    }

    private function repository(): string
    {
        $repository = (string) config('static-delivery.cloudflare.github_repository', 'eltx1/hm01');
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
            throw new StaticDeliveryException('REPOSITORY_INVALID', 'Configured delivery repository is invalid.');
        }

        return $repository;
    }

    private function managedPath(string $path): bool
    {
        return in_array($path, ['hm-loader.js', '_headers', '404.html', 'delivery-manifest.json'], true)
            || str_starts_with($path, 'configs/')
            || str_starts_with($path, 'assets/loader/')
            || str_starts_with($path, 'assets/prebid/');
    }
}
