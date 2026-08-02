<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignCreativeType;
use App\Models\Campaign;
use App\Models\CreativeFile;
use DOMDocument;
use DOMElement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

final class CreativeValidationService
{
    public function __construct(
        private readonly RemoteUrlSafetyValidator $remoteUrls,
        private readonly MalwareScanner $malware,
    ) {}

    public function validateAndStore(Campaign $campaign, CampaignCreativeType $type, array $data, ?UploadedFile $file): array
    {
        $landingUrl = $this->url($data['landing_url'] ?? $campaign->landing_url, 'landing_url');
        $clickUrl = $this->url($data['click_through_url'] ?? $landingUrl, 'click_through_url');
        $creative = [
            'name' => trim((string) $data['name']),
            'type' => $type,
            'width' => isset($data['width']) ? (int) $data['width'] : null,
            'height' => isset($data['height']) ? (int) $data['height'] : null,
            'landing_url' => $landingUrl,
            'click_through_url' => $clickUrl,
            'html_content' => filled($data['html_content'] ?? null) ? (string) $data['html_content'] : null,
            'vast_url' => filled($data['vast_url'] ?? null) ? $this->url($data['vast_url'], 'vast_url') : null,
            'native_assets' => $data['native_assets'] ?? null,
            'text_content' => filled($data['text_content'] ?? null) ? trim((string) $data['text_content']) : null,
        ];

        $this->validateTypePayload($type, $creative, $file);
        $fileData = $file ? $this->validateFile($campaign, $type, $file, $creative) : null;

        return ['creative' => $creative, 'file' => $fileData];
    }

    private function validateTypePayload(CampaignCreativeType $type, array $creative, ?UploadedFile $file): void
    {
        $errors = [];
        if (in_array($type, [CampaignCreativeType::Image, CampaignCreativeType::Html5], true) && ! $file) {
            $errors['creative_file'] = 'A creative file is required.';
        }
        if ($type === CampaignCreativeType::ThirdPartyTag) {
            if (! filled($creative['html_content'])) $errors['html_content'] = 'A third-party tag is required.';
            elseif ($reasons = $this->unsafeHtmlReasons((string) $creative['html_content'])) $errors['html_content'] = 'Unsafe tag: '.implode(', ', $reasons);
        }
        if ($type === CampaignCreativeType::Native) {
            $assets = $creative['native_assets'];
            if (! is_array($assets) || blank($assets['headline'] ?? null) || blank($assets['body'] ?? null)) {
                $errors['native_assets'] = 'Native creatives require headline and body assets.';
            }
        }
        if ($type === CampaignCreativeType::VideoVast && blank($creative['vast_url'])) $errors['vast_url'] = 'A valid VAST URL is required.';
        elseif ($type === CampaignCreativeType::VideoVast) $this->remoteUrls->assertPublicHttpUrl((string) $creative['vast_url'], 'vast_url');
        if ($type === CampaignCreativeType::Text && blank($creative['text_content'])) $errors['text_content'] = 'Text creative content is required.';
        if ($type === CampaignCreativeType::House && ! $file && blank($creative['html_content']) && blank($creative['text_content'])) {
            $errors['creative_file'] = 'A house creative requires a file, HTML, or text.';
        }
        if (filled($creative['html_content']) && ($reasons = $this->unsafeHtmlReasons((string) $creative['html_content']))) {
            $errors['html_content'] = 'Unsafe HTML: '.implode(', ', $reasons);
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function validateFile(Campaign $campaign, CampaignCreativeType $type, UploadedFile $file, array &$creative): array
    {
        if (! $file->isValid()) throw ValidationException::withMessages(['creative_file' => 'The upload did not complete successfully.']);

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        $allowedExtensions = config('campaigns.allowed_extensions.'.$type->value, []);
        $allowedMimes = config('campaigns.allowed_mime_types.'.$type->value, []);
        $max = (int) config('campaigns.max_file_bytes.'.$type->value, 0);
        $errors = [];
        $this->malware->scan($file->getRealPath());

        if (! in_array($extension, $allowedExtensions, true)) $errors['creative_file'] = 'The file extension is not allowed for this creative type.';
        if (! in_array($mime, $allowedMimes, true)) $errors['creative_file'] = 'The detected MIME type is not allowed for this creative type.';
        if ($max > 0 && $file->getSize() > $max) $errors['creative_file'] = 'The creative file exceeds the configured size limit.';

        $sha = hash_file('sha256', $file->getRealPath());
        if (CreativeFile::withoutGlobalScopes()->where('organization_id', $campaign->organization_id)->where('sha256', $sha)->exists()) {
            $errors['creative_file'] = 'This exact creative file has already been uploaded.';
        }

        $width = $height = null;
        $manifest = null;
        if (str_starts_with($mime, 'image/')) {
            $size = @getimagesize($file->getRealPath());
            if (! $size) $errors['creative_file'] = 'The image dimensions could not be read.';
            else {
                [$width, $height] = $size;
                if ($creative['width'] && $creative['width'] !== $width) $errors['width'] = 'The declared width does not match the image.';
                if ($creative['height'] && $creative['height'] !== $height) $errors['height'] = 'The declared height does not match the image.';
                $creative['width'] = $width;
                $creative['height'] = $height;
            }
        } elseif ($extension === 'zip') {
            $manifest = $this->validateHtml5Archive($file->getRealPath());
            $html = (string) ($manifest['index_html'] ?? '');
            if ($reasons = $this->unsafeHtmlReasons($html)) $errors['creative_file'] = 'Unsafe HTML5 archive: '.implode(', ', $reasons);
        }

        if ($errors) throw ValidationException::withMessages($errors);

        $disk = (string) config('campaigns.creative_disk', 'local');
        $directory = trim((string) config('campaigns.creative_directory', 'campaign-creatives'), '/').'/'.$campaign->public_key;
        $path = Storage::disk($disk)->putFileAs($directory, $file, $sha.'.'.$extension);
        if (! $path) throw ValidationException::withMessages(['creative_file' => 'The creative could not be stored.']);

        if (is_array($manifest)) unset($manifest['index_html']);

        return [
            'disk' => $disk, 'path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime, 'extension' => $extension, 'size_bytes' => $file->getSize(),
            'sha256' => $sha, 'width' => $width, 'height' => $height, 'asset_manifest' => $manifest,
        ];
    }

    private function validateHtml5Archive(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw ValidationException::withMessages(['creative_file' => 'The HTML5 ZIP archive cannot be opened.']);
        $files = [];
        $index = null;
        $totalBytes = 0;
        $maxFiles = (int) config('campaigns.html5_archive.max_files', 250);
        $maxBytes = (int) config('campaigns.html5_archive.max_uncompressed_bytes', 50 * 1024 * 1024);
        $maxRatio = (float) config('campaigns.html5_archive.max_compression_ratio', 100);
        if ($zip->numFiles > $maxFiles) {
            $zip->close();
            throw ValidationException::withMessages(['creative_file' => 'The HTML5 archive contains too many files.']);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_starts_with($name, '/') || str_contains($name, '../')) {
                $zip->close();
                throw ValidationException::withMessages(['creative_file' => 'The HTML5 archive contains an unsafe path.']);
            }
            $stat = $zip->statIndex($i) ?: [];
            $size = (int) ($stat['size'] ?? 0);
            $compressed = max(1, (int) ($stat['comp_size'] ?? 1));
            $totalBytes += $size;
            if ($totalBytes > $maxBytes || ($size / $compressed) > $maxRatio) {
                $zip->close();
                throw ValidationException::withMessages(['creative_file' => 'The HTML5 archive exceeds safe extraction limits.']);
            }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                $zip->close();
                throw ValidationException::withMessages(['creative_file' => 'Symbolic links are not allowed in HTML5 archives.']);
            }
            if (! str_ends_with($name, '/')) $files[] = $name;
            if (strtolower($name) === 'index.html') $index = $zip->getFromIndex($i);
        }
        if ($index === null || $index === false) {
            $zip->close();
            throw ValidationException::withMessages(['creative_file' => 'The HTML5 archive must contain index.html at its root.']);
        }

        $missing = [];
        foreach ($this->domUrls($index) as $asset) {
            if ($this->isExternalReference($asset)) continue;
            $asset = $this->normalizeLocalAsset($asset);
            if ($asset !== null && ! in_array($asset, $files, true)) $missing[] = $asset;
        }
        $zip->close();
        if ($missing) throw ValidationException::withMessages(['creative_file' => 'Missing HTML5 assets: '.implode(', ', array_unique($missing))]);

        return [
            'files' => $files,
            'total_uncompressed_bytes' => $totalBytes,
            'sandbox' => 'allow-scripts allow-popups allow-popups-to-escape-sandbox',
            'content_security_policy' => "default-src 'none'; img-src https: data:; media-src https:; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src https:; frame-ancestors 'none'; base-uri 'none'",
            'index_html' => $index,
        ];
    }

    private function unsafeHtmlReasons(string $html): array
    {
        $patterns = [
            'javascript URL' => '/javascript\s*:/i', 'cookie access' => '/document\s*\.\s*cookie/i',
            'local storage' => '/(?:localStorage|sessionStorage)/i', 'dynamic evaluation' => '/\b(?:eval|Function)\s*\(/i',
            'top navigation' => '/(?:window\s*\.\s*top|top\s*\.\s*location)/i', 'embedded object' => '/<(?:object|embed|applet)\b/i',
            'inline event handler' => '/\son[a-z]+\s*=/i', 'unsecured resource' => '/(?:src|href)\s*=\s*["\']http:\/\//i',
        ];
        $reasons = array_keys(array_filter($patterns, fn (string $pattern) => preg_match($pattern, $html) === 1));
        foreach ($this->domUrls($html) as $url) {
            $lower = strtolower(trim($url));
            if ($lower === '' || str_starts_with($lower, '#') || str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:')) continue;
            if (str_starts_with($lower, 'data:')) {
                if (! preg_match('#^data:image/(?:gif|png|jpeg|webp);base64,#i', $lower)) $reasons[] = 'unsafe data URL';
                continue;
            }
            if (! $this->isExternalReference($url)) continue;
            if (str_starts_with($url, '//') || ! str_starts_with($lower, 'https://')) {
                $reasons[] = 'non-HTTPS external resource';
                continue;
            }
            try {
                $this->remoteUrls->assertPublicHttpUrl($url, 'html_content');
            } catch (Throwable) {
                $reasons[] = 'private or invalid external resource';
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @return list<string> */
    private function domUrls(string $html): array
    {
        if (trim($html) === '' || ! class_exists(DOMDocument::class)) return [];
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML(
            '<!doctype html><html><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) return [];

        $urls = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) continue;
            foreach (['src', 'href', 'action', 'poster', 'data'] as $attribute) {
                if ($element->hasAttribute($attribute)) $urls[] = html_entity_decode(trim($element->getAttribute($attribute)), ENT_QUOTES | ENT_HTML5);
            }
            if ($element->hasAttribute('srcset')) {
                foreach (explode(',', $element->getAttribute('srcset')) as $candidate) {
                    $url = preg_split('/\s+/', trim($candidate), 2)[0] ?? '';
                    if ($url !== '') $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
                }
            }
        }

        return array_values(array_unique(array_filter($urls, fn ($value) => $value !== '')));
    }

    private function isExternalReference(string $url): bool
    {
        return str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1;
    }

    private function normalizeLocalAsset(string $url): ?string
    {
        $path = rawurldecode((string) strtok($url, '?#'));
        $path = preg_replace('#^(?:\./)+#', '', str_replace('\\', '/', $path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '../')) return null;

        return $path;
    }

    private function url(mixed $value, string $field): ?string
    {
        if (blank($value)) return null;
        $url = trim((string) $value);
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages([$field => 'A valid HTTP or HTTPS URL without embedded credentials is required.']);
        }
        return $url;
    }
}
