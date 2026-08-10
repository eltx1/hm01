<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Publisher;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'actor_id' => ['nullable', 'ulid'],
            'event' => ['nullable', 'string', 'max:160'],
            'organization_id' => ['nullable', 'ulid'],
            'publisher_id' => ['nullable', 'ulid'],
            'site_id' => ['nullable', 'ulid'],
            'auditable_type' => ['nullable', 'string', 'max:255'],
            'auditable_id' => ['nullable', 'string', 'max:64'],
            'route' => ['nullable', 'string', 'max:160'],
            'ip' => ['nullable', 'ip'],
            'q' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
        ]);

        $query = AuditLog::query()
            ->with(['actor', 'organization'])
            ->latest('created_at')
            ->latest('id');

        $this->applyFilters($query, $filters);

        return view('admin.audit.index', [
            'logs' => $query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString(),
            'filters' => $filters,
            'actors' => User::query()->orderBy('name')->limit(250)->get(['id', 'name', 'email']),
            'organizations' => Organization::query()->orderBy('name')->limit(250)->get(['id', 'name']),
            'publishers' => Publisher::withoutGlobalScopes()->orderBy('display_name')->limit(250)->get(['id', 'organization_id', 'display_name', 'legal_name']),
            'sites' => Site::withoutGlobalScopes()->orderBy('display_name')->limit(500)->get(['id', 'publisher_id', 'display_name', 'primary_domain']),
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($value = $filters['from'] ?? null) {
            $query->where('created_at', '>=', Carbon::parse($value)->startOfDay());
        }
        if ($value = $filters['to'] ?? null) {
            $query->where('created_at', '<=', Carbon::parse($value)->endOfDay());
        }
        if ($value = $filters['actor_id'] ?? null) {
            $query->where('actor_id', $value);
        }
        if ($value = $filters['event'] ?? null) {
            $query->where('event', 'like', '%'.$this->escapeLike($value).'%');
        }
        if ($value = $filters['organization_id'] ?? null) {
            $query->where('organization_id', $value);
        }
        if ($value = $filters['publisher_id'] ?? null) {
            $query->where(function (Builder $nested) use ($value): void {
                $nested->where(fn (Builder $auditable) => $auditable
                    ->where('auditable_type', (new Publisher())->getMorphClass())
                    ->where('auditable_id', $value))
                    ->orWhere(function (Builder $siteAudit) use ($value): void {
                        $siteIds = Site::withoutGlobalScopes()->where('publisher_id', $value)->select('id');
                        $siteAudit->where('auditable_type', (new Site())->getMorphClass())
                            ->whereIn('auditable_id', $siteIds);
                    });
            });
        }
        if ($value = $filters['site_id'] ?? null) {
            $query->where('auditable_type', (new Site())->getMorphClass())->where('auditable_id', $value);
        }
        if ($value = $filters['auditable_type'] ?? null) {
            $query->where('auditable_type', $value);
        }
        if ($value = $filters['auditable_id'] ?? null) {
            $query->where('auditable_id', $value);
        }
        if ($value = $filters['route'] ?? null) {
            $query->where('metadata->route', 'like', '%'.$this->escapeLike($value).'%');
        }
        if ($value = $filters['ip'] ?? null) {
            $query->where('ip_address', $value);
        }
        if ($value = $filters['q'] ?? null) {
            $like = '%'.$this->escapeLike($value).'%';
            $query->where(function (Builder $nested) use ($like): void {
                $nested->where('event', 'like', $like)
                    ->orWhere('auditable_type', 'like', $like)
                    ->orWhere('auditable_id', 'like', $like)
                    ->orWhere('request_id', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('user_agent', 'like', $like);
            });
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }
}
