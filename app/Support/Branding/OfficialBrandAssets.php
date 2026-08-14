<?php

namespace App\Support\Branding;

use InvalidArgumentException;

final class OfficialBrandAssets
{
    /** @var array<string, array{path: string, sha256: string, width: int, height: int, role: string}> */
    private const ASSETS = [
        'full_logo' => [
            'path' => 'assets/brand/horusmedia-logo-official.png',
            'sha256' => '4c239a11a95dcf240fbcf65f7ccf1c3d1ff324d71bd9b2d602f4f6b53457ec07',
            'width' => 630,
            'height' => 787,
            'role' => 'Full official Horus Media logo',
        ],
        'emblem' => [
            'path' => 'assets/brand/horusmedia-emblem.png',
            'sha256' => 'b0d56cdde7b0c2fb1ece237a08a142030be686ffbdf44cd49c1e76ad504c38c6',
            'width' => 453,
            'height' => 448,
            'role' => 'Primary Horus Media emblem',
        ],
        'header_emblem' => [
            'path' => 'assets/brand/horusmedia-emblem-header.png',
            'sha256' => '5f2ec6f697f1c4113e0c1633fe716c109830ff360ce09477ef6ee6ea174a24bf',
            'width' => 240,
            'height' => 237,
            'role' => 'Compact Horus Media header emblem',
        ],
        'hero_emblem' => [
            'path' => 'assets/brand/horusmedia-emblem-hero.png',
            'sha256' => 'b0d56cdde7b0c2fb1ece237a08a142030be686ffbdf44cd49c1e76ad504c38c6',
            'width' => 453,
            'height' => 448,
            'role' => 'Large Horus Media hero emblem',
        ],
        'social' => [
            'path' => 'assets/brand/horusmedia-social.jpg',
            'sha256' => '698f30f63d14a62cbc8a319cfc62d9364a999c73e6b4f63d8307814e8a27bf8e',
            'width' => 1200,
            'height' => 630,
            'role' => 'Official social sharing image',
        ],
        'favicon' => [
            'path' => 'assets/brand/favicon.png',
            'sha256' => '8160724053fd4d831a49695b273a880b5b877f62e66a08d9e6cf300417d11aba',
            'width' => 512,
            'height' => 512,
            'role' => 'Official Horus Media browser icon',
        ],
    ];

    /** @return array{path: string, sha256: string, width: int, height: int, role: string} */
    public function metadata(string $key): array
    {
        return self::ASSETS[$key] ?? throw new InvalidArgumentException("Unknown official brand asset [{$key}].");
    }

    /** @return array<string, array{path: string, sha256: string, width: int, height: int, role: string}> */
    public function all(): array
    {
        return self::ASSETS;
    }

    public function exists(string $key): bool
    {
        return is_file(public_path($this->metadata($key)['path']));
    }

    public function url(string $key): ?string
    {
        $asset = $this->metadata($key);
        if (! is_file(public_path($asset['path']))) {
            return null;
        }

        return asset($asset['path']).'?v='.substr($asset['sha256'], 0, 12);
    }
}
