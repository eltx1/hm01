<?php

namespace App\Support\Branding;

use App\Enums\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final readonly class BrandIdentityResolver
{
    public function __construct(private OfficialBrandAssets $assets) {}

    public function official(string $variant = 'emblem'): BrandIdentity
    {
        return new BrandIdentity(
            name: 'Horus Media',
            descriptor: 'Advertising Control Plane',
            logoUrl: $this->assets->url($this->officialAssetKey($variant)),
            logoAlt: 'Horus Media emblem',
            usesTenantLogo: false,
        );
    }

    public function forWorkspace(?User $user, string $variant = 'emblem'): BrandIdentity
    {
        $organization = $user?->organization;
        if (! $organization || $organization->type === OrganizationType::HorusMedia) {
            return $this->official($variant);
        }

        $name = $organization->dashboard_title ?: $organization->name ?: 'Horus Media';
        if ($organization->logo_path && Storage::disk('public')->exists($organization->logo_path)) {
            return new BrandIdentity(
                name: $name,
                descriptor: 'Horus Media workspace',
                logoUrl: Storage::disk('public')->url($organization->logo_path),
                logoAlt: $name.' logo',
                usesTenantLogo: true,
            );
        }

        return new BrandIdentity(
            name: $name,
            descriptor: 'Powered by Horus Media',
            logoUrl: $this->assets->url($this->officialAssetKey($variant)),
            logoAlt: 'Horus Media emblem',
            usesTenantLogo: false,
        );
    }

    private function officialAssetKey(string $variant): string
    {
        return match ($variant) {
            'header' => 'header_emblem',
            'hero' => 'hero_emblem',
            'full' => 'full_logo',
            default => 'emblem',
        };
    }
}
