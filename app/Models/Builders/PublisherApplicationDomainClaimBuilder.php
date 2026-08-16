<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

final class PublisherApplicationDomainClaimBuilder extends Builder
{
    public function delete()
    {
        $reserved = (clone $this)->where(function ($query): void {
            $query->whereNotNull('publisher_seller_declaration_id')
                ->orWhereNotNull('website_seller_declaration_id')
                ->orWhereNotNull('verification_requested_at');
        });

        if ($reserved->exists()) {
            return $reserved->update([
                'claim_status' => 'RELEASED',
                'released_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return parent::delete();
    }
}
