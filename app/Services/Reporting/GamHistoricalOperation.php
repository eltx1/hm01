<?php

namespace App\Services\Reporting;

use App\Models\GamRevenueCorrection;
use App\Models\GamRevenueCorrectionReceipt;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;

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
        return $this->operate($mode, $operation, $actorFingerprint, $limit, $digest);
    }

    /** One step of the separately authorized, finite deployment operation. */
    public function advanceOneTime(array $contract): array
    {
        $keys = array_keys($contract); sort($keys);
        $date = is_string($contract['through'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $contract['through']) : false;
        if ($keys !== ['actor_selector', 'operation', 'schema_version', 'through'] || ($contract['schema_version'] ?? null) !== 1
            || ! is_string($contract['operation'] ?? null) || ! self::hex($contract['operation'])
            || ! is_string($contract['actor_selector'] ?? null) || ! self::hex($contract['actor_selector'])
            || ! $date || $date->format('Y-m-d') !== $contract['through']) {
            return $this->result(is_string($contract['operation'] ?? null) ? $contract['operation'] : '', null, 'INVALID_INPUT');
        }
        return $this->operate('advance', $contract['operation'], $contract['actor_selector'], 1, null, $contract);
    }

    private function operate(string $mode, string $operation, string $actorFingerprint, int $limit, ?string $digest,
        ?array $contract = null): array
    {
        $manifest = null;
        if ((! in_array($mode, self::MODES, true) && ! ($mode === 'advance' && $contract !== null)) || ! self::hex($operation) || ! self::hex($actorFingerprint)
            || $limit < 1 || $limit > 6 || ($digest !== null && $digest !== '' && ! self::hex($digest))
            || ($mode === 'apply' && ! self::hex($digest ?? ''))) return $this->result($operation, null, 'INVALID_INPUT');
        $lock = null;
        try {
            $actor = $this->actor($actorFingerprint, $contract ? $operation : null);
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
                if (($manifest['version'] ?? null) !== ($contract ? 2 : 1) || $manifest['operation'] !== $operation || $manifest['actor_id'] !== $actor->id
                    || ! hash_equals($manifest['actor_fingerprint'], $actorFingerprint)
                    || ($contract !== null && GamRevenueCorrectionService::hash($manifest['contract'] ?? []) !== GamRevenueCorrectionService::hash($contract))) {
                    $manifest = null;
                    throw new \RuntimeException('OPERATION_CONFLICT');
                }
            } elseif (! in_array($mode, ['discover', 'advance'], true)) throw new \RuntimeException('OPERATION_MISSING');
            if (! $manifest) {
                $discovery = $this->inventory->discover($contract['through'] ?? null);
                $manifest = ['version' => $contract ? 2 : 1, 'operation' => $operation, 'actor_id' => $actor->id, 'actor_fingerprint' => $actorFingerprint,
                    'initial_counts' => $discovery['counts'], 'coverage' => $discovery['coverage'], 'reasons' => $discovery['reasons'],
                    'records' => $discovery['records'], 'windows' => array_map(fn ($window) => $window + ['state' => 'NEW',
                        'candidate_id' => null, 'candidate_digest' => null, 'review' => null, 'receipt_id' => null], $discovery['windows']),
                    'batch' => [], 'digest' => null, 'created_at' => now()->toIso8601String()];
                if ($contract !== null) $manifest['contract'] = $contract;
                $this->save($path, $manifest);
            }
            $this->refresh($manifest, $actor);
            $current = $this->inventory->discover($contract['through'] ?? null);
            if (! hash_equals(GamRevenueCorrectionService::hash($manifest['records']), GamRevenueCorrectionService::hash($current['records']))) {
                throw new \RuntimeException('STALE_INVENTORY');
            }
            if ($mode === 'advance') {
                $this->partitionObserved($manifest, $actor);
                $ready = array_values(array_filter($manifest['windows'], fn ($window) => $window['state'] === 'READY'
                    && ($window['review']['passed'] ?? false)));
                if ($ready) {
                    $manifest['batch'] = [$ready[0]['key']];
                    $manifest['batch_inventory_digest'] = GamRevenueCorrectionService::hash($manifest['records']);
                    $manifest['batch_coverage'] = $manifest['coverage'];
                    $manifest['digest'] = $digest = $this->digest($manifest);
                    $this->save($path, $manifest);
                    $mode = 'apply';
                } elseif (array_filter($manifest['windows'], fn ($window) => $window['state'] === 'PENDING')) $mode = 'poll';
                elseif (array_filter($manifest['windows'], fn ($window) => $window['state'] === 'NEW')) $mode = 'prepare';
                else $mode = 'status';
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
                        if (GamRevenueCorrectionService::hash($manifest['records']) !== GamRevenueCorrectionService::hash($this->inventory->discover($contract['through'] ?? null)['records'])) {
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
                $indices = array_keys($manifest['windows']);
                if ($contract !== null && $mode === 'prepare') {
                    // Finish observed children before opening more original
                    // windows, preserving their fresh one-hour evidence budget.
                    usort($indices, fn ($a, $b) => (count($manifest['windows'][$b]['ancestor_evidence'] ?? [])
                        <=> count($manifest['windows'][$a]['ancestor_evidence'] ?? [])) ?: ($a <=> $b));
                }
                foreach ($indices as $index) {
                    $window = $manifest['windows'][$index];
                    if ($processed >= $limit) break;
                    if ($mode === 'prepare' && $window['state'] === 'NEW') {
                        $active = GamRevenueCorrection::where('actor_id', $actor->id)->where('expires_at', '>', now())
                            ->whereNotIn('status', ['APPLIED', 'SUPERSEDED'])->count();
                        if ($active >= 6) {
                            if ($contract !== null) throw new \RuntimeException('CAPACITY_REACHED');
                            break;
                        }
                        // Persist intent before any external report call. An uncertain intent never restarts automatically.
                        $manifest['windows'][$index]['state'] = 'STARTING';
                        $this->save($path, $manifest);
                        $this->assertAncestors($window, $actor);
                        $overlap = GamRevenueCorrection::where('source_connection_id', $window['source_connection_id'])
                            ->whereDate('period_start', '<=', $window['to'])->whereDate('period_end', '>=', $window['from'])
                            ->where('status', '!=', 'SUPERSEDED')
                            ->whereNotIn('id', array_column($window['ancestor_evidence'] ?? [], 'candidate_id'))->get();
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
                if ($contract !== null) $manifest['batch_coverage'] = $manifest['coverage'];
                $manifest['digest'] = $manifest['batch'] ? $this->digest($manifest) : null;
            }
            if ($contract !== null) $this->updateCoverage($manifest);
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

    /** Preserve the blocked full query; every child will obtain its own fresh report. */
    private function partitionObserved(array &$manifest, User $actor): void
    {
        foreach (array_keys($manifest['windows']) as $index) {
            $window = $manifest['windows'][$index];
            if ($window['state'] !== 'BLOCKED') continue;
            $candidate = $this->candidate($window, $actor);
            if (($candidate->job['status'] ?? null) !== 'COMPLETED') continue;
            $review = $this->reviewer->review($candidate, $window);
            foreach (['candidate_digest', 'currency', 'unit', 'hostname', 'current_facts', 'financial_state', 'pre_cutover'] as $check) {
                if (! ($review['checks'][$check] ?? false)) continue 2;
            }
            if (count($candidate->snapshot['finance']['adjustments'] ?? []) > 128) continue;
            $original = [];
            foreach ($candidate->snapshot['facts'] as $row) {
                $day = substr($row['fact']['report_date'], 0, 10);
                if (isset($original[$day])) throw new \RuntimeException('OPERATION_CONFLICT');
                $original[$day] = $row['fact']['id'];
            }
            ksort($original);
            $expected = $window['fact_ids']; $actual = array_values($original); sort($expected); sort($actual);
            if ($actual !== $expected || count($original) !== count($candidate->proposal['days'] ?? [])) throw new \RuntimeException('OPERATION_CONFLICT');
            $observed = $unresolved = [];
            foreach ($candidate->proposal['days'] as $day) {
                $date = $day['date'];
                if (! isset($original[$date]) || $day['original_fact_id'] !== $original[$date]) throw new \RuntimeException('OPERATION_CONFLICT');
                $fresh = $candidate->job['result']['days'][$date] ?? null;
                if ($fresh !== null && $day['flags'] === [] && $day['projected'] !== null
                    && GamRevenueCorrectionService::hash($fresh) === GamRevenueCorrectionService::hash($day['fresh'])) {
                    $observed[$date] = $original[$date];
                } else $unresolved[$date] = ['fact_id' => $original[$date], 'reason' => $fresh === null ? 'NO_EXACT_SITE_ROW' : 'UNVERIFIED_OBSERVED_DAY',
                    'flags' => $day['flags']];
            }
            // A pure financial/global blocker is never bypassed by making an
            // identical child. Every partition is a strict observed subset.
            if (! $observed || ! $unresolved) continue;
            ksort($observed); ksort($unresolved);
            $parent = ['candidate_id' => $candidate->id, 'digest' => $candidate->digest,
                'evidence_hash' => $this->evidenceHash($candidate), 'from' => $window['from'], 'to' => $window['to'],
                'fact_ids' => $window['fact_ids']];
            $children = []; $child = null;
            foreach ($observed as $day => $factId) {
                if ($child && CarbonImmutable::parse($child['to'])->addDay()->toDateString() !== $day) {
                    $children[] = $child; $child = null;
                }
                if (! $child) $child = array_intersect_key($window, array_flip(['site_id', 'binding_id', 'source_connection_id']))
                    + ['from' => $day, 'to' => $day, 'fact_ids' => [],
                        'ancestor_evidence' => [...($window['ancestor_evidence'] ?? []), $parent]];
                $child['to'] = $day; $child['fact_ids'][] = $factId;
            }
            if ($child) $children[] = $child;
            $childKeys = [];
            foreach ($children as $child) {
                $child['key'] = GamRevenueCorrectionService::hash($child);
                $childKeys[] = $child['key'];
                $manifest['windows'][] = $child + ['state' => 'NEW', 'candidate_id' => null, 'candidate_digest' => null,
                    'review' => null, 'receipt_id' => null];
                foreach ($child['fact_ids'] as $factId) $manifest['coverage']['daily:'.$factId] = [
                    'state' => 'OBSERVED_CHILD', 'parent_window' => $window['key'], 'window' => $child['key']];
            }
            foreach ($unresolved as $day => $gap) $manifest['coverage']['daily:'.$gap['fact_id']] = [
                'state' => 'BLOCKED', 'reason' => $gap['reason'], 'date' => $day, 'parent_window' => $window['key']];
            $manifest['windows'][$index]['state'] = 'PARTITIONED';
            $manifest['windows'][$index]['partition'] = ['evidence_hash' => $parent['evidence_hash'],
                'children' => $childKeys, 'unresolved' => $unresolved];
        }
    }

    private function evidenceHash(GamRevenueCorrection $candidate): string
    {
        return GamRevenueCorrectionService::hash([$candidate->context, $candidate->snapshot,
            $candidate->query_hash, $candidate->job, $candidate->proposal, $candidate->digest]);
    }

    private function assertAncestors(array $window, User $actor): void
    {
        $ancestors = $window['ancestor_evidence'] ?? [];
        if (count($ancestors) > 30 || count(array_unique(array_column($ancestors, 'candidate_id'))) !== count($ancestors)) {
            throw new \RuntimeException('OPERATION_CONFLICT');
        }
        foreach ($ancestors as $evidence) {
            $parent = GamRevenueCorrection::findOrFail($evidence['candidate_id']);
            $actualIds = array_column(array_column($parent->snapshot['facts'], 'fact'), 'id');
            $expectedIds = $evidence['fact_ids']; sort($actualIds); sort($expectedIds);
            $digest = GamRevenueCorrectionService::hash(['version' => 1, 'candidate_id' => $parent->id, 'actor_id' => $parent->actor_id,
                'context' => $parent->context, 'snapshot' => $parent->snapshot['fingerprint'], 'query_hash' => $parent->query_hash,
                'job' => $parent->job, 'proposal' => $parent->proposal]);
            if ($parent->status !== 'BLOCKED' || ($parent->job['status'] ?? null) !== 'COMPLETED'
                || $parent->actor_id !== $actor->id || $parent->site_id !== $window['site_id']
                || $parent->source_connection_id !== $window['source_connection_id']
                || $parent->period_start !== $evidence['from'] || $parent->period_end !== $evidence['to']
                || $window['from'] < $evidence['from'] || $window['to'] > $evidence['to']
                || count($window['fact_ids']) >= count($expectedIds) || array_diff($window['fact_ids'], $expectedIds)
                || $actualIds !== $expectedIds || ! is_string($parent->digest) || ! hash_equals($digest, $parent->digest)
                || ! hash_equals($parent->digest, $evidence['digest']) || ! hash_equals($this->evidenceHash($parent), $evidence['evidence_hash'])) {
                throw new \RuntimeException('OPERATION_CONFLICT');
            }
            $observedIds = [];
            $days = array_column($parent->proposal['days'], null, 'date');
            for ($day = CarbonImmutable::parse($window['from']); $day->toDateString() <= $window['to']; $day = $day->addDay()) {
                $date = $day->toDateString(); $observed = $days[$date] ?? null;
                if (! $observed || $observed['flags'] !== [] || $observed['projected'] === null
                    || ! isset($parent->job['result']['days'][$date])) throw new \RuntimeException('OPERATION_CONFLICT');
                $observedIds[] = $observed['original_fact_id'];
            }
            $childIds = $window['fact_ids']; sort($childIds); sort($observedIds);
            if ($childIds !== $observedIds) throw new \RuntimeException('OPERATION_CONFLICT');
        }
    }

    private function updateCoverage(array &$manifest): void
    {
        foreach ($manifest['windows'] as $window) {
            if (in_array($window['state'], ['PARTITIONED', 'APPLIED'], true)) continue;
            $blocked = ! in_array($window['state'], ['NEW', 'PENDING'], true)
                && ! ($window['state'] === 'READY' && ($window['review']['passed'] ?? false));
            $reason = match ($window['state']) { 'STARTING' => 'CANDIDATE_UNCERTAIN', 'EXPIRED' => 'CANDIDATE_EXPIRED',
                'READY' => 'REVIEW_REQUIRED', default => 'CANDIDATE_BLOCKED' };
            foreach ($window['fact_ids'] as $factId) {
                $key = 'daily:'.$factId;
                $manifest['coverage'][$key]['state'] = $blocked ? 'BLOCKED' : ($window['state'] === 'READY' ? 'READY' : 'PENDING');
                $manifest['coverage'][$key]['window'] = $window['key'];
                if ($blocked) $manifest['coverage'][$key]['reason'] = $reason;
                else unset($manifest['coverage'][$key]['reason']);
            }
        }
    }

    private function actor(string $fingerprint, ?string $operation = null): User
    {
        $matches = [];
        foreach (User::query()->select(['id', 'email'])->orderBy('id')->cursor() as $user) {
            $candidate = hash('sha256', strtolower(trim($user->email)));
            if ($operation !== null) $candidate = hash('sha256', $operation.':'.$candidate);
            if (hash_equals($fingerprint, $candidate)) $matches[] = $user->id;
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
        if ($window['state'] === 'PARTITIONED') return;
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
            if (($manifest['version'] ?? 1) === 2) foreach ($window['fact_ids'] as $factId) {
                $manifest['coverage']['daily:'.$factId] = ($manifest['coverage']['daily:'.$factId] ?? [])
                    + ['receipt_id' => $receipt->id, 'receipt_digest' => $receipt->digest];
                $manifest['coverage']['daily:'.$factId]['state'] = 'CORRECTED';
            }
            return;
        }
        $window['state'] = $candidate->expires_at->lte(now()) ? 'EXPIRED' : $candidate->status;
        $window['candidate_digest'] = $candidate->digest;
        if ($window['state'] === 'READY') $window['review'] = $this->reviewer->review($candidate, $window);
    }

    private function candidate(array $window, User $actor): GamRevenueCorrection
    {
        $this->assertAncestors($window, $actor);
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
        $payload = ['version' => 1, 'operation' => $manifest['operation'], 'actor_id' => $manifest['actor_id'],
            'coverage' => $manifest['batch_coverage'] ?? $manifest['coverage'], 'inventory_digest' => $manifest['batch_inventory_digest'] ?? GamRevenueCorrectionService::hash($manifest['records']),
            'batch' => $batch];
        if (isset($manifest['contract'])) $payload['contract'] = $manifest['contract'];
        return GamRevenueCorrectionService::hash($payload);
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
        if (($manifest['version'] ?? 1) === 2) {
            $counts['windows'] = $counts['eligible_windows'] = count(array_filter($manifest['windows'], fn ($window) => $window['state'] !== 'PARTITIONED'));
            $counts['blocked_facts'] = $counts['corrected_facts'] = 0; $reasons = [];
            foreach ($manifest['coverage'] as $covered) {
                if ($covered['state'] === 'CORRECTED') $counts['corrected_facts']++;
                elseif ($covered['state'] === 'BLOCKED') {
                    $counts['blocked_facts']++;
                    $reasons[$covered['reason']] = ($reasons[$covered['reason']] ?? 0) + 1;
                }
            }
        }
        foreach ($manifest['windows'] ?? [] as $window) {
            if ($window['state'] === 'PARTITIONED') continue;
            if ($window['state'] === 'APPLIED') { $counts['applied']++; if (($manifest['version'] ?? 1) !== 2) $counts['corrected_facts'] += count($window['fact_ids']); }
            elseif ($window['state'] === 'READY' && ($window['review']['passed'] ?? false)) $counts['ready']++;
            elseif (in_array($window['state'], ['NEW', 'PENDING'], true)) $counts['pending']++;
            else {
                $counts['blocked']++;
                $code = match ($window['state']) { 'STARTING' => 'CANDIDATE_UNCERTAIN', 'EXPIRED' => 'CANDIDATE_EXPIRED', 'READY' => 'REVIEW_REQUIRED', default => 'CANDIDATE_BLOCKED' };
                if (($manifest['version'] ?? 1) !== 2) $reasons[$code] = ($reasons[$code] ?? 0) + 1;
            }
        }
        $blocked = $counts['blocked_facts'] > 0 || $counts['blocked'] > 0 || $reason !== 'NONE';
        return ['schema_version' => 1, 'outcome' => $reason === 'OPERATION_FAILED' ? 'FAILED' : ($blocked ? 'BLOCKED' : 'OK'),
            'reason' => $reason, 'operation' => self::hex($operation) ? $operation : str_repeat('0', 64),
            'digest' => $manifest['digest'] ?? null, 'counts' => $counts, 'reasons' => $reasons];
    }

    private static function hex(string $value): bool { return preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
}
