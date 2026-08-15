<?php

namespace App\Services\SupplyChain;

use App\Services\SupplyChain\Exceptions\SupplyChainValidationException;
use InvalidArgumentException;

final class SupplyChainObjectValidator
{
    public function __construct(
        private readonly DomainNormalizer $domains,
        private readonly PublicExtensionGuard $extensions,
    ) {}

    /** @return array<int, string> */
    public function validate(array $schain): array
    {
        $errors = [];
        $unexpectedRoot = array_diff(array_keys($schain), ['complete', 'ver', 'nodes', 'ext']);
        if ($unexpectedRoot !== []) {
            $errors[] = 'The schain contains unsupported root field(s): '.implode(', ', $unexpectedRoot).'.';
        }
        if (! is_int($schain['complete'] ?? null) || ! in_array($schain['complete'], [0, 1], true)) {
            $errors[] = 'schain.complete must be integer 0 or 1.';
        }
        if (($schain['ver'] ?? null) !== '1.0') {
            $errors[] = 'schain.ver must be the current final SupplyChain specification value 1.0.';
        }
        if (array_key_exists('ext', $schain)) {
            $errors = array_merge($errors, $this->extensions->validate($schain['ext'], 'schain.ext'));
        }

        $nodes = $schain['nodes'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes) || $nodes === []) {
            $errors[] = 'schain.nodes must contain at least one explicitly configured node.';
            return array_values(array_unique($errors));
        }
        if (count($nodes) > 50) {
            $errors[] = 'schain.nodes exceeds the Horus safety bound.';
        }

        $seen = [];
        foreach ($nodes as $index => $node) {
            $label = 'schain node '.($index + 1);
            if (! is_array($node)) {
                $errors[] = $label.' must be an object.';
                continue;
            }
            $unexpected = array_diff(array_keys($node), ['asi', 'sid', 'hp', 'rid', 'name', 'domain', 'ext']);
            if ($unexpected !== []) {
                $errors[] = $label.' contains unsupported field(s): '.implode(', ', $unexpected).'.';
            }
            try {
                if (! is_string($node['asi'] ?? null) || $this->domains->normalize($node['asi']) !== $node['asi']) {
                    $errors[] = $label.' has a non-canonical asi domain.';
                }
            } catch (InvalidArgumentException) {
                $errors[] = $label.' has an invalid asi domain.';
            }
            $sid = $node['sid'] ?? null;
            if (! is_string($sid) || $sid === '' || strlen($sid) > 64 || preg_match('/[\s,\x00-\x1F\x7F]/u', $sid)) {
                $errors[] = $label.' has an invalid sid.';
            }
            if (($node['hp'] ?? null) !== 1) {
                $errors[] = 'Every SupplyChain 1.0 node must have hp=1.';
            }
            foreach (['rid' => 255, 'name' => 255] as $field => $max) {
                if (array_key_exists($field, $node)
                    && (! is_string($node[$field]) || trim($node[$field]) === '' || mb_strlen($node[$field]) > $max || $this->hasUnsafeText($node[$field]))) {
                    $errors[] = $label.' has an invalid optional '.$field.'.';
                }
            }
            if (array_key_exists('domain', $node)) {
                try {
                    if (! is_string($node['domain']) || $this->domains->normalize($node['domain']) !== $node['domain']) {
                        $errors[] = $label.' domain must be canonical PSL+1.';
                    }
                } catch (InvalidArgumentException) {
                    $errors[] = $label.' has an invalid optional domain.';
                }
            }
            if (array_key_exists('ext', $node)) {
                $errors = array_merge($errors, $this->extensions->validate($node['ext'], 'schain.nodes.'.($index + 1).'.ext'));
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

    private function hasUnsafeText(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }
}
