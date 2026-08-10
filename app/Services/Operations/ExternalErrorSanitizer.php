<?php

namespace App\Services\Operations;

final class ExternalErrorSanitizer
{
    public function sanitize(?string $message, int $limit = 1000): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $value = $message;
        $patterns = [
            '/(authorization\s*:\s*bearer\s+)[^\s,;]+/i',
            '/\b(password|passwd|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|secret|private[_-]?key)\b\s*[:=]\s*["\']?[^\s,"\';}]+/i',
            '/("(?:password|api_key|apiKey|token|access_token|refresh_token|client_secret|secret)"\s*:\s*")[^"]*(")/i',
        ];
        $replacements = [
            '$1[REDACTED]',
            '$1=[REDACTED]',
            '$1[REDACTED]$2',
        ];

        $value = preg_replace($patterns, $replacements, $value) ?? '[REDACTED ERROR]';

        return mb_substr($value, 0, $limit);
    }
}
