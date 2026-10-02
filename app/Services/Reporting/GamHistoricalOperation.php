<?php

namespace App\Services\Reporting;

use App\Models\GamRevenueCorrection;
use App\Models\GamRevenueCorrectionReceipt;
use App\Models\Site;
use App\Models\User;

/** Private orchestration only. The existing correction service owns every financial write. */
final class GamHistoricalOperation
{
    public const MODES = ['discover', 'prepare', 'poll', 'status', 'apply'];
    public const REASONS = ['NONE', 'INVALID_INPUT', 'ACTOR_UNVERIFIED', 'OPERATION_MISSING', 'OPERATION_CONFLICT',
        'STALE_INVENTORY', 'DIGEST_MISMATCH', 'REVIEW_REQUIRED', 'CANDIDATE_BLOCKED', 'CANDIDATE_EXPIRED',
        'CANDIDATE_UNCERTAIN', 'CAPACITY_REACHED', 'OPERATION_FAILED'];

    public function __construct(
        private readonly GamHistoricalInventory $inventory,
        private readonly GamHistoricalOperationReview $reviewer,
        private readonly GamRevenueCorrectionService $corrections,
    ) {}

    public function run(string $mode, string $operation, string $actorFingerprint, int $limit = 1, ?string $digest = null): array
    {
        $manifest = null;
        if (! in_array($mode, self::MODES, true) || ! self::hex($operation) || ! self::hex($actorFingerprint)
            || $limit < 1 || $limit > 6 || ($digest !== null && $digest !== '' && ! self::hex($digest))
            || ($mode === 'apply' && ! self::hex($digest ?? ''))) return $this->result($operation, null, 'INVALID_INPUT');
        $lock = null;
        try {
            $actor = $this->actor($actorFingerprint);
            $directory = storage_path('app/private/gam-historical-operations');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) throw new \RuntimeException('OPERATION_FAILED');
            if (is_link($directory) || (fileperms($directory) & 0077) !== 0) throw new \RuntimeException('OPERATION_FAILED');
            $path = $directory.'/'.$operation.'.json';
            $lockPath = $directory.'/'.$operation.'.lock';
            if (is_link($path) || is_link($lockPath)) throw new \RuntimeException('OPERATION_FAILED');
            $lock = fopen($lockPath, 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('OPERATION_CONFLICT');
            chmod($lockPath, 0600);
            if (is_file($path)) {
                $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (($manifest['version'] ?? null) !== 1 || $manifest['operation'] !== $operation || $manifest['actor_id'] !== $actor->id
                    || ! hash_equals($manifest['actor_fingerprint'], $actorFingerprint)) {
                    $manifest = null;
                    throw new \RuntimeException('OPERATION_CONFLICT');
                }
            } elseif ($mode !== 'discover') throw new \RuntimeException('OPERATION_MISSING');
            if (! $manifest) {
                $discovery = $this->inventory->discover();
                $manifest = ['version' => 1, 'operation' => $operation, 'actor_id' => $actor->id, 'actor_fingerprint' => $actorFingerprint,
                    'initial_counts' => $discovery['counts'], 'coverage' => $discovery['coverage'], 'reasons' => $discovery['reasons'],
                    'records' => $discovery['records'], 'windows' => array_map(fn ($window) => $window + ['state' => 'NEW',
                        'candidate_id' => null, 'candidate_digest' => null, 'review' => null, 'receipt_id' => null], $discovery['windows']),
                    'batch' => [], 'digest' => null, 'created_at' => now()->toIso8601String()];
                $this->save($path, $manifest);
            }
            $this->refresh($manifest, $actor);
            $current = $this->inventory->discover();
            if (! hash_equals(GamRevenueCorrectionService::hash($manifest['records']), GamRevenueCorrectionService::hash($current['records']))) {
                throw new \RuntimeException('STALE_INVENTORY');
            }
            if ($mode === 'apply') {
                if (! is_string($manifest['digest']) || ! hash_equals($manifest['digest'], $digest)
                    || ! hash_equals($this->digest($manifest), $digest)) throw new \RuntimeException('DIGEST_MISMATCH');
                if (! $manifest['batch']) throw new \RuntimeException('REVIEW_REQUIRED');
                if (count($manifest['batch']) > $limit) throw new \RuntimeException('INVALID_INPUT');
                // Preflight the entire reviewed batch before its first financial transaction.
                foreach ($manifest['batch'] as $key) {
                    $window = $this->window($manifest, $key);
                    if ($window['state'] === 'APPLIED') continue;
                    $candidate = $this->candidate($window, $actor);
                    if ($candidate->expires_at->lte(now())) throw new \RuntimeException('CANDIDATE_EXPIRED');
                    $review = $this->reviewer->review($candidate, $window);
                    if (! $review['passed'] || ! hash_equals(GamRevenueCorrectionService::hash($window['review'] ?? []), GamRevenueCorrectionService::hash($review))) {
                        throw new \RuntimeException('REVIEW_REQUIRED');
                    }
                }
                foreach ($manifest['batch'] as $key) {
                    $index = array_search($key, array_column($manifest['windows'], 'key'), true);
                    $window = &$manifest['windows'][$index];
                    if ($window['state'] !== 'APPLIED') {
                        if (GamRevenueCorrectionService::hash($manifest['records']) !== GamRevenueCorrectionService::hash($this->inventory->discover()['records'])) {
                            throw new \RuntimeException('STALE_INVENTORY');
                        }
                        $this->corrections->apply($this->candidate($window, $actor), $window['candidate_digest'],
                            'Private historical operation '.$operation.'; reviewed exact-site Ad Exchange USD evidence and original revenue rules.', $actor);
                        $this->refreshWindow($manifest, $index, $actor);
                        // The receipt is committed by the service. Persist each checkpoint independently.
                        $this->save($path, $manifest);
                    }
                    unset($window);
                }
                // Keep the reviewed batch/digest for a receipt-only idempotent retry.
            } elseif ($mode === 'prepare' || $mode === 'poll') {
                $processed = 0;
                foreach ($manifest['windows'] as $index => $window) {
                    if ($processed >= $limit) break;
                    if ($mode === 'prepare' && $window['state'] === 'NEW') {
                        $active = GamRevenueCorrection::where('actor_id', $actor->id)->where('expires_at', '>', now())
                            ->whereNotIn('status', ['APPLIED', 'SUPERSEDED'])->count();
                        if ($active >= 6) break;
                        // Persist intent before any external report call. An uncertain intent never restarts automatically.
                        $manifest['windows'][$index]['state'] = 'STARTING';
                        $this->save($path, $manifest);
                        $overlap = GamRevenueCorrection::where('source_connection_id', $window['source_connection_id'])
                            ->whereDate('period_start', '<=', $window['to'])->whereDate('period_end', '>=', $window['from'])
                            ->where('status', '!=', 'SUPERSEDED')->get();
                        if ($overlap->isNotEmpty()) {
                            if ($overlap->count() !== 1 || $overlap[0]->actor_id !== $actor->id || $overlap[0]->period_start !== $window['from']
                                || $overlap[0]->period_end !== $window['to']) throw new \RuntimeException('CANDIDATE_UNCERTAIN');
                            $candidate = $overlap[0];
                            if ($candidate->expires_at->lte(now()) && in_array($candidate->status, ['READY', 'BLOCKED', 'FAILED'], true)) {
                                // A new discover/prepare operation is explicit fresh evidence for known terminal attempts.
                                // In-flight and applied evidence is never replaced, including after its TTL.
                                $manifest['windows'][$index]['candidate_history'][] = $candidate->id;
                                $this->save($path, $manifest);
                                $candidate = $this->corrections->replace($candidate, $actor);
                            }
                        } else {
                            $candidate = $this->corrections->start(Site::withoutGlobalScopes()->findOrFail($window['site_id']), $window['from'], $window['to'], $actor);
                        }
                        $manifest['windows'][$index]['candidate_id'] = $candidate->id;
                        $processed++;
                        $this->refreshWindow($manifest, $index, $actor);
                        $this->save($path, $manifest);
                    } elseif ($mode === 'poll' && $window['state'] === 'PENDING') {
                        $this->corrections->poll($this->candidate($window, $actor), $actor);
                        $processed++;
                        $this->refreshWindow($manifest, $index, $actor);
                        $this->save($path, $manifest);
                    }
                }
                $manifest['batch'] = array_values(array_column(array_filter($manifest['windows'], fn ($window) => $window['state'] === 'READY'
                    && ($window['review']['passed'] ?? false)), 'key'));
                $manifest['batch_inventory_digest'] = GamRevenueCorrectionService::hash($manifest['records']);
                $manifest['digest'] = $manifest['batch'] ? $this->digest($manifest) : null;
            }
            $this->save($path, $manifest);
            return $this->result($operation, $manifest);
        } catch (\Throwable $error) {
            if (isset($path) && $manifest !== null && is_resource($lock)) {
                try { $this->save($path, $manifest); } catch (\Throwable) {}
                $old = umask(0077);
                try {
                    $errorPath = $directory.'/'.$operation.'.errors.log';
                    if (! is_link($errorPath)) file_put_contents($errorPath, (string) $error.PHP_EOL, FILE_APPEND | LOCK_EX);
                } catch (\Throwable) {
                    // Failure to persist diagnostics must never disclose them publicly.
                } finally { umask($old); }
            }
            $reason = in_array($error->getMessage(), self::REASONS, true) ? $error->getMessage() : 'OPERATION_FAILED';
            return $this->result($operation, $manifest, $reason);
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function actor(string $fingerprint): User
    {
        $matches = [];
        foreach (User::query()->select(['id', 'email'])->orderBy('id')->cursor() as $user) {
            if (hash_equals($fingerprint, hash('sha256', strtolower(trim($user->email))))) $matches[] = $user->id;
        }
        if (count($matches) !== 1) throw new \RuntimeException('ACTOR_UNVERIFIED');
        $actor = User::with('organization', 'roles.permissions')->findOrFail($matches[0]);
        if (! $actor->isActive() || $actor->email_verified_at === null || ! $actor->isHorusAdministrator()
            || ! $actor->hasPermission('reporting.admin.view') || ! $actor->hasPermission('reporting.import')
            || ! $actor->hasPermission('finance.adjustments.approve')) throw new \RuntimeException('ACTOR_UNVERIFIED');
        return $actor;
    }

    private function refresh(array &$manifest, User $actor): void
    {
        foreach (array_keys($manifest['windows']) as $index) $this->refreshWindow($manifest, $index, $actor);
    }

    private function refreshWindow(array &$manifest, int $index, User $actor): void
    {
        $window = &$manifest['windows'][$index];
        if (! $window['candidate_id']) {
            if ($window['state'] !== 'STARTING') return;
            $matches = GamRevenueCorrection::where('actor_id', $actor->id)->where('site_id', $window['site_id'])
                ->whereDate('period_start', $window['from'])->whereDate('period_end', $window['to'])->where('status', '!=', 'SUPERSEDED')->get();
            if ($matches->count() !== 1) return;
            $window['candidate_id'] = $matches[0]->id;
        }
        $candidate = $this->candidate($window, $actor);
        if ($candidate->status === 'APPLIED') {
            $receipt = GamRevenueCorrectionReceipt::where('correction_id', $candidate->id)->sole();
            if (! $window['candidate_digest'] || ! hash_equals($window['candidate_digest'], $receipt->digest)
                || ! hash_equals($candidate->digest, $receipt->digest)) throw new \RuntimeException('CANDIDATE_UNCERTAIN');
            $receiptIds = array_column(array_column($receipt->after['facts'], 'fact'), 'id');
            $expectedIds = $window['fact_ids']; sort($receiptIds); sort($expectedIds);
            if ($receiptIds !== $expectedIds) throw new \RuntimeException('CANDIDATE_UNCERTAIN');
            foreach ($receipt->after['facts'] as $row) {
                if (! in_array($row['fact']['id'], $window['fact_ids'], true)) throw new \RuntimeException('CANDIDATE_UNCERTAIN');
                $manifest['records']['daily:'.$row['fact']['id']] = GamHistoricalInventory::factHash($row['fact'], $row['dimension']);
            }
            $manifest['records']['receipt:'.$receipt->id] = GamHistoricalInventory::receiptHash($receipt);
            ksort($manifest['records']);
            $window['receipt_id'] = $receipt->id;
            $window['state'] = 'APPLIED';
            return;
        }
        $window['state'] = $candidate->expires_at->lte(now()) ? 'EXPIRED' : $candidate->status;
        $window['candidate_digest'] = $candidate->digest;
        if ($window['state'] === 'READY') $window['review'] = $this->reviewer->review($candidate, $window);
    }

    private function candidate(array $window, User $actor): GamRevenueCorrection
    {
        $candidate = GamRevenueCorrection::findOrFail($window['candidate_id']);
        if ($candidate->actor_id !== $actor->id || $candidate->site_id !== $window['site_id']
            || $candidate->source_connection_id !== $window['source_connection_id']
            || $candidate->period_start !== $window['from'] || $candidate->period_end !== $window['to']) throw new \RuntimeException('OPERATION_CONFLICT');
        return $candidate;
    }

    private function window(array $manifest, string $key): array
    {
        foreach ($manifest['windows'] as $window) if ($window['key'] === $key) return $window;
        throw new \RuntimeException('OPERATION_CONFLICT');
    }

    private function digest(array $manifest): string
    {
        // Initial inventory is stable across this batch's own committed receipt checkpoints.
        $batch = array_map(fn ($key) => $this->window($manifest, $key), $manifest['batch']);
        foreach ($batch as &$window) unset($window['state'], $window['receipt_id']);
        unset($window);
        return GamRevenueCorrectionService::hash(['version' => 1, 'operation' => $manifest['operation'], 'actor_id' => $manifest['actor_id'],
            'coverage' => $manifest['coverage'], 'inventory_digest' => $manifest['batch_inventory_digest'] ?? GamRevenueCorrectionService::hash($manifest['records']),
            'batch' => $batch]);
    }

    private function save(string $path, array $manifest): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($temporary, json_encode($manifest, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($temporary, $path)) throw new \RuntimeException('OPERATION_FAILED');
        } finally { umask($old); }
    }

    private function result(string $operation, ?array $manifest, string $reason = 'NONE'): array
    {
        $counts = $manifest['initial_counts'] ?? array_fill_keys(['sources', 'daily_facts', 'hourly_facts', 'windows', 'eligible_windows',
            'blocked_facts', 'corrected_facts', 'forward_facts', 'pending', 'ready', 'applied', 'blocked'], 0);
        $reasons = $manifest['reasons'] ?? [];
        foreach ($manifest['windows'] ?? [] as $window) {
            if ($window['state'] === 'APPLIED') { $counts['applied']++; $counts['corrected_facts'] += count($window['fact_ids']); }
            elseif ($window['state'] === 'READY' && ($window['review']['passed'] ?? false)) $counts['ready']++;
            elseif (in_array($window['state'], ['NEW', 'PENDING'], true)) $counts['pending']++;
            else {
                $counts['blocked']++;
                $code = match ($window['state']) { 'STARTING' => 'CANDIDATE_UNCERTAIN', 'EXPIRED' => 'CANDIDATE_EXPIRED', 'READY' => 'REVIEW_REQUIRED', default => 'CANDIDATE_BLOCKED' };
                $reasons[$code] = ($reasons[$code] ?? 0) + 1;
            }
        }
        $blocked = $counts['blocked_facts'] > 0 || $counts['blocked'] > 0 || $reason !== 'NONE';
        return ['schema_version' => 1, 'outcome' => $reason === 'OPERATION_FAILED' ? 'FAILED' : ($blocked ? 'BLOCKED' : 'OK'),
            'reason' => $reason, 'operation' => self::hex($operation) ? $operation : str_repeat('0', 64),
            'digest' => $manifest['digest'] ?? null, 'counts' => $counts, 'reasons' => $reasons];
    }

    private static function hex(string $value): bool { return preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
}
