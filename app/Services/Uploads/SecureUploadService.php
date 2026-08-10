<?php

namespace App\Services\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SecureUploadService
{
    public function store(UploadedFile $file, string $directory, array $allowedMimeMap, int $maxBytes): array
    {
        return $this->storeAs($file, $directory, $allowedMimeMap, $maxBytes, null);
    }

    public function storeRandomized(UploadedFile $file, string $directory, array $allowedMimeMap, int $maxBytes): array
    {
        return $this->storeAs($file, $directory, $allowedMimeMap, $maxBytes, Str::lower(Str::random(48)));
    }

    private function storeAs(UploadedFile $file, string $directory, array $allowedMimeMap, int $maxBytes, ?string $randomName): array
    {
        if (! $file->isValid() || $file->getSize() <= 0 || $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is invalid or exceeds the configured size limit.']);
        }
        $mime = strtolower((string) $file->getMimeType());
        $extension = $allowedMimeMap[$mime] ?? null;
        if (! $extension) {
            throw ValidationException::withMessages(['file' => 'The uploaded file type is not permitted.']);
        }
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = trim($directory, '/').'/'.($randomName ?: $checksum).'.'.$extension;
        $stream = fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream) || ! Storage::disk('local')->put($path, $stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw ValidationException::withMessages(['file' => 'The private file could not be stored.']);
        }
        fclose($stream);

        return ['path' => $path, 'mime' => $mime, 'extension' => $extension, 'checksum' => $checksum, 'size' => $file->getSize()];
    }
}
