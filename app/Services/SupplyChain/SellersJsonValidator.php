<?php

namespace App\Services\SupplyChain;

use App\Enums\SellerType;
use App\Services\SupplyChain\Exceptions\SupplyChainValidationException;
use InvalidArgumentException;

final class SellersJsonValidator
{
    public function __construct(
        private readonly DomainNormalizer $domains,
        private readonly PublicExtensionGuard $extensions,
    ) {}

    /** @return array<int, string> */
    public function validate(array $payload): array
    {
        $errors = [];
        $allowedTopLevel = ['contact_email', 'contact_address', 'version', 'identifiers', 'sellers', 'ext'];
        $unexpected = array_diff(array_keys($payload), $allowedTopLevel);
        if ($unexpected !== []) {
            $errors[] = 'Unexpected top-level field(s): '.implode(', ', $unexpected).'.';
        }
        if (($payload['version'] ?? null) !== '1.0') {
            $errors[] = 'version must be the final sellers.json specification value 1.0.';
        }
        if (! isset($payload['sellers']) || ! is_array($payload['sellers']) || ! array_is_list($payload['sellers'])) {
            $errors[] = 'sellers must be a JSON array.';
        }

        $email = $payload['contact_email'] ?? null;
        if ($email !== null && (! is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = 'contact_email must be a valid email address when supplied.';
        }
        $address = $payload['contact_address'] ?? null;
        if ($address !== null && (! is_string($address) || trim($address) === '' || mb_strlen($address) > 1000 || $this->hasUnsafeText($address))) {
            $errors[] = 'contact_address must be bounded safe text when supplied.';
        }
        if (array_key_exists('ext', $payload)) {
            $errors = array_merge($errors, $this->extensions->validate($payload['ext'], 'ext'));
        }

        $identifiers = $payload['identifiers'] ?? [];
        if (! is_array($identifiers) || ! array_is_list($identifiers)) {
            $errors[] = 'identifiers must be a JSON array when supplied.';
        } else {
            foreach ($identifiers as $index => $identifier) {
                if (! is_array($identifier) || array_diff(array_keys($identifier), ['name', 'value']) !== []
                    || ! is_string($identifier['name'] ?? null) || trim($identifier['name']) === ''
                    || ! is_string($identifier['value'] ?? null) || trim($identifier['value']) === ''
                    || mb_strlen($identifier['name']) > 64 || mb_strlen($identifier['value']) > 255
                    || $this->hasUnsafeText($identifier['name']) || $this->hasUnsafeText($identifier['value'])) {
                    $errors[] = 'Identifier '.($index + 1).' must contain only bounded name and value strings.';
                }
            }
        }

        $sellerIds = [];
        foreach (is_array($payload['sellers'] ?? null) ? $payload['sellers'] : [] as $index => $seller) {
            if (! is_array($seller)) {
                $errors[] = 'Seller '.($index + 1).' must be an object.';
                continue;
            }
            $unexpected = array_diff(array_keys($seller), [
                'seller_id', 'seller_type', 'is_confidential', 'is_passthrough', 'name', 'domain', 'comment', 'ext',
            ]);
            if ($unexpected !== []) {
                $errors[] = 'Seller '.($index + 1).' has unexpected field(s): '.implode(', ', $unexpected).'.';
            }

            $sellerId = $seller['seller_id'] ?? null;
            if (! is_string($sellerId) || $sellerId === '' || strlen($sellerId) > 64 || preg_match('/[\s,\x00-\x1F\x7F]/u', $sellerId)) {
                $errors[] = 'Seller '.($index + 1).' has an invalid seller_id.';
            } elseif (isset($sellerIds[$sellerId])) {
                $errors[] = 'seller_id '.$sellerId.' appears more than once.';
            } else {
                $sellerIds[$sellerId] = true;
            }

            if (! is_string($seller['seller_type'] ?? null) || ! SellerType::tryFrom(strtoupper($seller['seller_type']))) {
                $errors[] = 'Seller '.($index + 1).' has an invalid seller_type.';
            }
            foreach (['is_confidential', 'is_passthrough'] as $flag) {
                if (array_key_exists($flag, $seller) && (! is_int($seller[$flag]) || ! in_array($seller[$flag], [0, 1], true))) {
                    $errors[] = 'Seller '.($index + 1).' '.$flag.' must be integer 0 or 1.';
                }
            }

            $confidential = $seller['is_confidential'] ?? 0;
            if ($confidential === 1) {
                if (array_key_exists('name', $seller) || array_key_exists('domain', $seller)) {
                    $errors[] = 'Confidential seller '.($index + 1).' must omit public name and domain in Horus public output.';
                }
            } else {
                $name = $seller['name'] ?? null;
                if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255 || $this->hasUnsafeText($name)) {
                    $errors[] = 'Non-confidential seller '.($index + 1).' requires a safe public name.';
                }
                if (array_key_exists('domain', $seller)) {
                    try {
                        if (! is_string($seller['domain']) || $this->domains->normalize($seller['domain']) !== $seller['domain']) {
                            $errors[] = 'Seller '.($index + 1).' domain must be a canonical PSL+1 business domain.';
                        }
                    } catch (InvalidArgumentException) {
                        $errors[] = 'Seller '.($index + 1).' has an invalid business domain.';
                    }
                }
            }

            if (array_key_exists('comment', $seller)
                && (! is_string($seller['comment']) || mb_strlen($seller['comment']) > 1000 || $this->hasUnsafeText($seller['comment']))) {
                $errors[] = 'Seller '.($index + 1).' comment must be bounded safe text.';
            }
            if (array_key_exists('ext', $seller)) {
                $errors = array_merge($errors, $this->extensions->validate($seller['ext'], 'sellers.'.($index + 1).'.ext'));
            }
        }

        $ordered = array_keys($sellerIds);
        $sorted = $ordered;
        sort($sorted, SORT_STRING);
        if ($ordered !== $sorted) {
            $errors[] = 'Sellers must be ordered by seller_id.';
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(array $payload): void
    {
        $errors = $this->validate($payload);
        if ($errors !== []) {
            throw new SupplyChainValidationException('INVALID_SELLERS_JSON', $errors);
        }
    }

    private function hasUnsafeText(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }
}
