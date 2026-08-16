<?php

namespace App\Services\Prebid;

use App\Enums\BidderSellersJsonStatus;
use App\Models\BidderAdsTxtRecord;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Campaigns\RemoteUrlSafetyValidator;
use App\Services\SupplyChain\DomainNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class BidderSellersJsonVerifier
{
    public function __construct(
        private readonly RemoteUrlSafetyValidator $urls,
        private readonly DomainNormalizer $domains,
        private readonly AuditRecorder $audit,
    ) {}

    public function verify(BidderAdsTxtRecord $record, ?User $actor = null): BidderAdsTxtRecord
    {
        $started = hrtime(true);
        try {
            $domain = $this->domains->normalize($record->advertising_system_domain);
            $url = 'https://'.$domain.'/sellers.json';
            $addresses = $this->urls->publicAddresses($url, 'sellers_json_url');
            $address = collect($addresses)->first(fn (string $item): bool => filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $addresses[0];
            $response = Http::connectTimeout(3)->timeout(8)->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$domain.':443:'.$address]],
            ])->withHeaders([
                'User-Agent' => 'HorusMedia-SellersJson-Validator/1.0',
                'Accept' => 'application/json, text/plain;q=0.8',
            ])->get($url);

            if (! $response->successful()) {
                return $this->store($record, BidderSellersJsonStatus::Unreachable, 'HTTP_'.$response->status(), $actor, $started);
            }
            $maxBytes = 1_048_576;
            $body = $response->body();
            if (strlen($body) > $maxBytes) {
                return $this->store($record, BidderSellersJsonStatus::Unverified, 'RESPONSE_TOO_LARGE', $actor, $started);
            }
            $payload = json_decode($body, true);
            if (! is_array($payload) || ! isset($payload['sellers']) || ! is_array($payload['sellers'])) {
                return $this->store($record, BidderSellersJsonStatus::Unverified, 'INVALID_JSON', $actor, $started);
            }

            $matches = collect($payload['sellers'])->filter(fn ($seller): bool => is_array($seller)
                && (string) ($seller['seller_id'] ?? '') === (string) $record->publisher_account_id)->values();
            if ($matches->isEmpty()) {
                return $this->store($record, BidderSellersJsonStatus::Conflict, 'SELLER_ID_ABSENT', $actor, $started);
            }
            $fingerprints = $matches->map(fn (array $seller): string => hash('sha256', json_encode([
                'seller_id' => (string) ($seller['seller_id'] ?? ''),
                'seller_type' => (string) ($seller['seller_type'] ?? ''),
                'name' => (string) ($seller['name'] ?? ''),
                'domain' => (string) ($seller['domain'] ?? ''),
                'is_confidential' => (int) ($seller['is_confidential'] ?? 0),
            ], JSON_UNESCAPED_SLASHES)))->unique();
            if ($fingerprints->count() > 1) {
                return $this->store($record, BidderSellersJsonStatus::Conflict, 'AMBIGUOUS_SELLER_ID', $actor, $started);
            }

            return $this->store($record, BidderSellersJsonStatus::Verified, null, $actor, $started);
        } catch (ConnectionException) {
            return $this->store($record, BidderSellersJsonStatus::Unreachable, 'CONNECTION_FAILED', $actor, $started);
        } catch (Throwable) {
            return $this->store($record, BidderSellersJsonStatus::Unreachable, 'UNSAFE_OR_INVALID_TARGET', $actor, $started);
        }
    }

    private function store(BidderAdsTxtRecord $record, BidderSellersJsonStatus $status, ?string $errorCode, ?User $actor, int $started): BidderAdsTxtRecord
    {
        $before = $record->only(['remote_verification_status', 'remote_error_code', 'remote_verified_at', 'metadata']);
        $metadata = array_merge((array) $record->metadata, [
            'remote_sellers_json' => [
                'duration_ms' => max(0, (int) ((hrtime(true) - $started) / 1_000_000)),
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
        $record->update([
            'remote_verification_status' => $status,
            'remote_error_code' => $errorCode,
            'remote_verified_at' => now(),
            'metadata' => $metadata,
        ]);
        if ($actor) {
            $this->audit->record('prebid.bidder_sellers_json.verified', $record->organization_id, $actor, $record, $before, $record->fresh()->only(array_keys($before)));
        }

        return $record->refresh();
    }
}
