<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignCreativeType;
use App\Models\Campaign;
use App\Models\CreativeFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class CreativeValidationService
{
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
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_starts_with($name, '/') || str_contains($name, '../')) {
                $zip->close();
                throw ValidationException::withMessages(['creative_file' => 'The HTML5 archive contains an unsafe path.']);
            }
            if (! str_ends_with($name, '/')) $files[] = $name;
            if (strtolower($name) === 'index.html') $index = $zip->getFromIndex($i);
        }
        if ($index === null || $index === false) {
            $zip->close();
            throw ValidationException::withMessages(['creative_file' => 'The HTML5 archive must contain index.html at its root.']);
        }

        preg_match_all('/(?:src|href)=["\'](?!https?:|data:|#|mailto:)([^"\']+)["\']/i', $index, $matches);
        $missing = [];
        foreach ($matches[1] ?? [] as $asset) {
            $asset = strtok($asset, '?#');
            if ($asset && ! in_array($asset, $files, true)) $missing[] = $asset;
        }
        $zip->close();
        if ($missing) throw ValidationException::withMessages(['creative_file' => 'Missing HTML5 assets: '.implode(', ', array_unique($missing))]);

        return ['files' => $files, 'index_html' => $index];
    }

    private function unsafeHtmlReasons(string $html): array
    {
        $patterns = [
            'javascript URL' => '/javascript\s*:/i', 'cookie access' => '/document\s*\.\s*cookie/i',
            'local storage' => '/(?:localStorage|sessionStorage)/i', 'dynamic evaluation' => '/\b(?:eval|Function)\s*\(/i',
            'top navigation' => '/(?:window\s*\.\s*top|top\s*\.\s*location)/i', 'embedded object' => '/<(?:object|embed|applet)\b/i',
            'inline event handler' => '/\son[a-z]+\s*=/i', 'unsecured resource' => '/(?:src|href)\s*=\s*["\']http:\/\//i',
        ];
        return array_keys(array_filter($patterns, fn (string $pattern) => preg_match($pattern, $html) === 1));
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
