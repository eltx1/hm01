<?php

namespace App\Services\Operations;

use App\Models\LoaderRelease;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LoaderReleaseManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function activate(LoaderRelease $release, User $actor): LoaderRelease
    {
        return DB::transaction(function () use ($release, $actor): LoaderRelease {
            $source = public_path(ltrim($release->source_path, '/'));
            $minified = public_path(ltrim($release->minified_path, '/'));
            if (! is_file($source) || ! is_file($minified)) throw new RuntimeException('The selected loader release files are unavailable.');
            if (hash_file('sha256', $minified) !== $release->checksum) throw new RuntimeException('The selected loader release checksum does not match.');
            LoaderRelease::query()->where('id', '!=', $release->id)->update(['is_active' => false]);
            $release->update(['is_active' => true, 'published_at' => now()]);
            $this->audit->record('operations.loader.rollback', $actor->organization_id, $actor, $release, newValues: [
                'version' => $release->version, 'checksum' => $release->checksum,
            ]);
            return $release->refresh();
        });
    }
}
