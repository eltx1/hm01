<?php

namespace App\Services\StaticDelivery\Drivers;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use App\Services\StaticDelivery\StaticPathGuard;
use Illuminate\Support\Facades\File;

final class LocalFilesystemStaticDeliveryDriver implements StaticDeliveryDriverInterface
{
    public function __construct(private readonly StaticPathGuard $pathGuard) {}

    public function name(): string { return 'local'; }

    public function deliver(StaticDeliverySnapshot $snapshot, StaticDeliveryBatch $batch): StaticDeliveryResult
    {
        $root = rtrim((string) config('static-delivery.local_root'), DIRECTORY_SEPARATOR);
        if ($root === '' || $root === DIRECTORY_SEPARATOR) {
            throw new StaticDeliveryException('LOCAL_ROOT_INVALID', 'Static delivery local root is unsafe.');
        }
        File::ensureDirectoryExists($root);
        foreach (File::allFiles($root) as $existing) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $existing->getRelativePathname());
            if ($this->managedPath($relative) && ! array_key_exists($relative, $snapshot->files)) {
                File::delete($existing->getPathname());
            }
        }
        foreach ($snapshot->files as $relative => $contents) {
            $relative = $this->pathGuard->path($relative);
            $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($target));
            $temporary = $target.'.tmp.'.bin2hex(random_bytes(6));
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $target)) {
                @unlink($temporary);
                throw new StaticDeliveryException('LOCAL_WRITE_FAILED', 'Unable to atomically write the static delivery snapshot.');
            }
        }

        return new StaticDeliveryResult(
            remoteId: 'local:'.$snapshot->manifestHash,
            remoteUrl: 'file://'.$root,
            confirmedDeployed: true,
            metadata: ['manifest_hash' => $snapshot->manifestHash],
        );
    }

    private function managedPath(string $path): bool
    {
        return in_array($path, ['hm-loader.js', '_headers', '_routes.json', '404.html', 'delivery-manifest.json', 'sellers.json'], true)
            || str_starts_with($path, 'configs/')
            || str_starts_with($path, 'assets/')
            || str_starts_with($path, 'health/')
            || str_starts_with($path, 'supply/')
            || str_starts_with($path, 'traffic-gate/');
    }
}
