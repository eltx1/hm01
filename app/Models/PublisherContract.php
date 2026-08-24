<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\SupplyChain\HorusSellerIdentityService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PublisherContract extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'publisher_id', 'contract_reference', 'starts_at', 'ends_at', 'auto_renews', 'revenue_share_percent', 'payment_threshold', 'currency', 'payment_terms', 'contract_file_path', 'contract_file_name', 'contract_file_mime', 'status', 'internal_notes', 'created_by'];

    protected $hidden = ['internal_notes', 'contract_file_path'];

    protected static function booted(): void
    {
        // Commercial pricing/payment fields are account data and must never reopen or
        // disable HMP/HMS identity review. The only seller-lifecycle consequence of a
        // Contract mutation is loss of the Publisher's final current representation.
        static::saved(function (PublisherContract $contract): void {
            self::disableSellerIfNoCurrentRepresentation($contract->publisher_id);
        });

        static::deleted(function (PublisherContract $contract): void {
            self::disableSellerIfNoCurrentRepresentation($contract->publisher_id);
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'date', 'ends_at' => 'date', 'auto_renews' => 'boolean',
            'revenue_share_percent' => 'decimal:2', 'payment_threshold' => 'decimal:2',
            'status' => ContractStatus::class,
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    private static function disableSellerIfNoCurrentRepresentation(?string $publisherId): void
    {
        if (! $publisherId) {
            return;
        }

        $today = now()->toDateString();
        // Remove only tenant scoping. Keep SoftDeletes intact so a deleted Contract can
        // never satisfy the representation requirement after its deleted event fires.
        $represented = self::withoutGlobalScope('organization')
            ->where('publisher_id', $publisherId)
            ->whereIn('status', [ContractStatus::Signed->value, ContractStatus::Active->value])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today))
            ->exists();

        if ($represented) {
            return;
        }

        $publisher = Publisher::withoutGlobalScopes()->find($publisherId);
        if ($publisher) {
            app(HorusSellerIdentityService::class)->disableForUnrepresentedPublisher($publisher);
        }
    }
}
