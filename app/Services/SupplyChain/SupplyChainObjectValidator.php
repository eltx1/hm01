<?php

namespace App\Services\SupplyChain;

use App\Services\SupplyChain\Exceptions\SupplyChainValidationException;
use InvalidArgumentException;

final class SupplyChainObjectValidator
{
    public function __construct(private readonly DomainNormalizer $domains) {}

    /** @return array<int, string> */
    public function validate(array $schain): array
    {
        $errors = [];
        if (array_diff(array_keys($schain), ['complete', 'ver', 'nodes']) !== []) {
            $errors[] = 'The generated schain contains unsupported fields.';
        }
        if (! is_int($schain['complete'] ?? null) || ! in_array($schain['complete'], [0, 1], true)) {
            $errors[] = 'schain.complete must be integer 0 or 1.';
        }
        if (($schain['ver'] ?? null) !== '1.0') {
            $errors[] = 'schain.ver must be 1.0 while SupplyChain 1.1 remains non-final.';
        }
        $nodes = $schain['nodes'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes) || $nodes === []) {
            $errors[] = 'schain.nodes must contain at least one explicitly configured node.';

            return $errors;
        }

        $seen = [];
        foreach ($nodes as $index => $node) {
            if (! is_array($node) || array_diff(array_keys($node), ['asi', 'sid', 'hp']) !== []) {
                $errors[] = 'schain node '.($index + 1).' contains unsupported fields.';

                continue;
            }
            try {
                if (! is_string($node['asi'] ?? null) || $this->domains->normalize($node['asi']) !== $node['asi']) {
                    $errors[] = 'schain node '.($index + 1).' has a non-canonical asi domain.';
                }
            } catch (InvalidArgumentException) {
                $errors[] = 'schain node '.($index + 1).' has an invalid asi domain.';
            }
            $sid = $node['sid'] ?? null;
            if (! is_string($sid) || $sid === '' || strlen($sid) > 64 || preg_match('/[\s,\x00-\x1F\x7F]/u', $sid)) {
                $errors[] = 'schain node '.($index + 1).' has an invalid sid.';
            }
            if (($node['hp'] ?? null) !== 1) {
                $errors[] = 'Every SupplyChain 1.0 node must have hp=1.';
            }
            $key = ($node['asi'] ?? '')."\0".($sid ?? '');
            if (isset($seen[$key])) {
                $errors[] = 'The same asi and sid pair appears more than once in schain.';
            }
            $seen[$key] = true;
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(array $schain): void
    {
        $errors = $this->validate($schain);
        if ($errors !== []) {
            throw new SupplyChainValidationException('INVALID_SCHAIN', $errors);
        }
    }
}
