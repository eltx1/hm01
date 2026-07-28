<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SessionInvalidator
{
    public function invalidate(User $user, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
