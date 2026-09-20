<?php

namespace App\Services\StaticDelivery;

/**
 * Pages' unkeyed BLAKE3-128 asset identifier, not an authentication primitive.
 * Wire format: BLAKE3(base64(contents) + extension).hex.slice(0, 32).
 * References: cloudflare/workers-sdk packages/deploy-helpers/src/deploy/helpers/hash.ts;
 * BLAKE3-team/BLAKE3 reference_impl/reference_impl.rs (CC0 / Apache-2.0).
 * Pure PHP keeps the publisher portable without a Node or native extension runtime.
 */
final class PagesAssetHash
{
    private const IV = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
    private const PERMUTATION = [2, 6, 3, 10, 7, 0, 4, 13, 1, 11, 12, 5, 9, 14, 15, 8];

    public function asset(string $path, string $contents): string
    {
        return substr($this->digest(base64_encode($contents).pathinfo($path, PATHINFO_EXTENSION)), 0, 32);
    }

    public function digest(string $input): string
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('Pages asset hashing requires 64-bit PHP.');
        }
        $stack = [];
        $chunks = max(1, (int) ceil(strlen($input) / 1024));
        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $bytes = substr($input, $chunk * 1024, 1024);
            $cv = self::IV;
            $blocks = max(1, (int) ceil(strlen($bytes) / 64));
            for ($block = 0; $block < $blocks; $block++) {
                $data = substr($bytes, $block * 64, 64);
                $words = array_values(unpack('V16', str_pad($data, 64, "\0")));
                $flags = ($block === 0 ? 1 : 0) | ($block === $blocks - 1 ? 2 : 0);
                $output = [$cv, $words, $chunk, strlen($data), $flags];
                if ($block !== $blocks - 1) {
                    $cv = array_slice($this->compress(...$output), 0, 8);
                }
            }
            if ($chunk === $chunks - 1) {
                break;
            }
            $cv = array_slice($this->compress(...$output), 0, 8);
            for ($count = $chunk + 1; ($count & 1) === 0; $count >>= 1) {
                $cv = array_slice($this->compress(self::IV, [...array_pop($stack), ...$cv], 0, 64, 4), 0, 8);
            }
            $stack[] = $cv;
        }
        while ($stack !== []) {
            $cv = array_slice($this->compress(...$output), 0, 8);
            $output = [self::IV, [...array_pop($stack), ...$cv], 0, 64, 4];
        }
        $output[2] = 0; // Root output block counter, not the input chunk counter.
        $output[4] |= 8;

        return bin2hex(pack('V8', ...array_slice($this->compress(...$output), 0, 8)));
    }

    private function compress(array $cv, array $words, int $counter, int $length, int $flags): array
    {
        $state = [...$cv, ...array_slice(self::IV, 0, 4), $counter & 0xffffffff, $counter >> 32, $length, $flags];
        for ($round = 0; $round < 7; $round++) {
            $this->mix($state, 0, 4, 8, 12, $words[0], $words[1]);
            $this->mix($state, 1, 5, 9, 13, $words[2], $words[3]);
            $this->mix($state, 2, 6, 10, 14, $words[4], $words[5]);
            $this->mix($state, 3, 7, 11, 15, $words[6], $words[7]);
            $this->mix($state, 0, 5, 10, 15, $words[8], $words[9]);
            $this->mix($state, 1, 6, 11, 12, $words[10], $words[11]);
            $this->mix($state, 2, 7, 8, 13, $words[12], $words[13]);
            $this->mix($state, 3, 4, 9, 14, $words[14], $words[15]);
            $words = array_map(fn ($i) => $words[$i], self::PERMUTATION);
        }
        for ($i = 0; $i < 8; $i++) {
            $state[$i] ^= $state[$i + 8];
            $state[$i + 8] ^= $cv[$i];
        }

        return $state;
    }

    private function mix(array &$s, int $a, int $b, int $c, int $d, int $x, int $y): void
    {
        $s[$a] = ($s[$a] + $s[$b] + $x) & 0xffffffff;
        $s[$d] = $this->rotate($s[$d] ^ $s[$a], 16);
        $s[$c] = ($s[$c] + $s[$d]) & 0xffffffff;
        $s[$b] = $this->rotate($s[$b] ^ $s[$c], 12);
        $s[$a] = ($s[$a] + $s[$b] + $y) & 0xffffffff;
        $s[$d] = $this->rotate($s[$d] ^ $s[$a], 8);
        $s[$c] = ($s[$c] + $s[$d]) & 0xffffffff;
        $s[$b] = $this->rotate($s[$b] ^ $s[$c], 7);
    }

    private function rotate(int $word, int $bits): int
    {
        return (($word >> $bits) | ($word << (32 - $bits))) & 0xffffffff;
    }
}
