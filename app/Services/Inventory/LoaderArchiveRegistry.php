<?php

namespace App\Services\Inventory;

use App\Models\LoaderRelease;
use Illuminate\Validation\ValidationException;

final class LoaderArchiveRegistry
{
    private ?array $records = null;
    private array $bytes = [];

    public function records(): array
    {
        if ($this->records !== null) return $this->records;
        $path = public_path('assets/loader/releases.json');
        if (is_link($path)) $this->invalid();
        if (! is_file($path)) return $this->records = [];
        if (filesize($path) > 131072) $this->invalid();
        try { $records = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { $this->invalid(); }
        if (! is_array($records) || count($records) > 256) $this->invalid();
        foreach ($records as $digest => $record) {
            if (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest) || ! is_array($record)
                || ($record['file'] ?? null) !== 'hm-loader.'.substr($digest, 0, 16).'.min.js'
                || ! is_string($record['runtimeBuild'] ?? null) || ! is_string($record['version'] ?? null)
                || ! preg_match('/^sha256:[a-f0-9]{64}$/D', $record['runtimeBuild'] ?? '')
                || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $record['version'] ?? '')
                || ($record['protocol'] ?? null) !== 2) $this->invalid();
            $asset = public_path('assets/loader/'.$record['file']);
            if (is_link($asset) || ! is_file($asset) || filesize($asset) > 524288) $this->invalid();
            $bytes = file_get_contents($asset);
            if (! hash_equals($digest, hash('sha256', $bytes)) || ! str_contains($bytes, $record['runtimeBuild'])) $this->invalid();
            $this->bytes[$digest] = $bytes;
        }
        return $this->records = $records;
    }

    public function assets(): array
    {
        $files = [];
        foreach ($this->records() as $digest => $record) $files['runtime/loader/'.$record['file']] = $this->bytes[$digest];
        return $files;
    }

    public function configuration(?LoaderRelease $release, int $version): array
    {
        $base = ['version' => $release?->version ?? '2.0.0',
            'assetUrl' => $release ? rtrim((string) config('horus.cdn_url'), '/').'/'.ltrim($release->minified_path, '/') : null,
            'cacheBust' => $version];
        if (! $release || ! str_starts_with($release->minified_path, 'runtime/loader/')) return $base;
        $digest = $release->checksum;
        $record = $this->records()[$digest] ?? null;
        if (! $record || $release->minified_path !== 'runtime/loader/'.$record['file']) $this->invalid();
        return array_replace($base, ['version' => $record['version'], 'runtimeBuild' => $record['runtimeBuild'],
            'integrity' => 'sha256-'.base64_encode(hex2bin($digest))]);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['loader_release' => 'The immutable Loader archive is unavailable or invalid. Restore the default release before publishing.']);
    }
}
