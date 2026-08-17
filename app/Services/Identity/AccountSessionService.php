<?php

namespace App\Services\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AccountSessionService
{
    /**
     * Return only safe, current-user-scoped session metadata.
     * Raw session ids and payloads never leave this service.
     *
     * @return array<int, array{reference:?string,current:bool,device:string,last_active_at:CarbonImmutable}>
     */
    public function sessionsFor(User $user, string $currentSessionId, ?string $currentUserAgent = null): array
    {
        if (! $this->usesDatabaseSessions()) {
            return [[
                'reference' => null,
                'current' => true,
                'device' => $this->deviceLabel($currentUserAgent),
                'last_active_at' => CarbonImmutable::now(),
            ]];
        }

        $minimumActivity = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;
        $rows = DB::table(config('session.table', 'sessions'))
            ->select(['id', 'user_agent', 'last_activity'])
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $minimumActivity)
            ->orderByDesc('last_activity')
            ->get();

        $sessions = [];
        $currentFound = false;

        foreach ($rows as $row) {
            $sessionId = (string) $row->id;
            $isCurrent = hash_equals($currentSessionId, $sessionId);
            $currentFound = $currentFound || $isCurrent;
            $sessions[] = [
                'reference' => $isCurrent ? null : $this->referenceFor($sessionId),
                'current' => $isCurrent,
                'device' => $this->deviceLabel(is_string($row->user_agent) ? $row->user_agent : null),
                'last_active_at' => CarbonImmutable::createFromTimestamp((int) $row->last_activity),
            ];
        }

        if (! $currentFound) {
            array_unshift($sessions, [
                'reference' => null,
                'current' => true,
                'device' => $this->deviceLabel($currentUserAgent),
                'last_active_at' => CarbonImmutable::now(),
            ]);
        }

        return $sessions;
    }

    public function revoke(User $user, string $reference, string $currentSessionId): bool
    {
        if (! $this->usesDatabaseSessions()) {
            return false;
        }

        $rows = DB::table(config('session.table', 'sessions'))
            ->select('id')
            ->where('user_id', $user->getKey())
            ->get();

        foreach ($rows as $row) {
            $sessionId = (string) $row->id;
            if (hash_equals($currentSessionId, $sessionId)) {
                continue;
            }

            if (hash_equals($this->referenceFor($sessionId), $reference)) {
                return DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->where('id', $sessionId)
                    ->delete() === 1;
            }
        }

        return false;
    }

    public function revokeOthers(User $user, string $currentSessionId): int
    {
        if (! $this->usesDatabaseSessions()) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    /**
     * A one-way, application-keyed reference suitable for an action URL.
     * It is deliberately not the database session id.
     */
    public function referenceFor(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }

    public function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }

    private function deviceLabel(?string $userAgent): string
    {
        if (! is_string($userAgent) || trim($userAgent) === '') {
            return 'Browser session';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform ? $browser.' on '.$platform : $browser.' session';
    }
}
