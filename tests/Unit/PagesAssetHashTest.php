<?php

namespace Tests\Unit;

use App\Services\StaticDelivery\PagesAssetHash;
use PHPUnit\Framework\TestCase;

class PagesAssetHashTest extends TestCase
{
    public function test_official_blake3_vectors_cover_block_chunk_and_tree_boundaries(): void
    {
        // BLAKE3-team/BLAKE3 test_vectors/test_vectors.json: input byte i % 251.
        $vectors = [
            0 => 'af1349b9f5f9a1a6a0404dea36dcc9499bcb25c9adc112b7cc9a93cae41f3262',
            1 => '2d3adedff11b61f14c886e35afa036736dcd87a74d27b5c1510225d0f592e213',
            63 => 'e9bc37a594daad83be9470df7f7b3798297c3d834ce80ba85d6e207627b7db7b',
            64 => '4eed7141ea4a5cd4b788606bd23f46e212af9cacebacdc7d1f4c6dc7f2511b98',
            65 => 'de1e5fa0be70df6d2be8fffd0e99ceaa8eb6e8c93a63f2d8d1c30ecb6b263dee',
            1023 => '10108970eeda3eb932baac1428c7a2163b0e924c9a9e25b35bba72b28f70bd11',
            1024 => '42214739f095a406f3fc83deb889744ac00df831c10daa55189b5d121c855af7',
            1025 => 'd00278ae47eb27b34faecf67b4fe263f82d5412916c1ffd97c8cb7fb814b8444',
            2048 => 'e776b6028c7cd22a4d0ba182a8bf62205d2ef576467e838ed6f2529b85fba24a',
            2049 => '5f4d72f40d7a5f82b15ca2b2e44b1de3c2ef86c426c95c1af0b6879522563030',
            3072 => 'b98cb0ff3623be03326b373de6b9095218513e64f1ee2edd2525c7ad1e5cffd2',
            3073 => '7124b49501012f81cc7f11ca069ec9226cecb8a2c850cfe644e327d22d3e1cd3',
            16384 => 'f875d6646de28985646f34ee13be9a576fd515f76b5b0a26bb324735041ddde4',
            31744 => '62b6960e1a44bcc1eb1a611a8d6235b6b4b78f32e7abc4fb4c6cdcce94895c47',
        ];
        $hasher = new PagesAssetHash;
        foreach ($vectors as $length => $expected) {
            $input = '';
            for ($i = 0; $i < $length; $i++) {
                $input .= chr($i % 251);
            }
            $this->assertSame($expected, $hasher->digest($input), 'Length '.$length);
        }
        $this->assertSame(substr($hasher->digest(base64_encode('{}').'json'), 0, 32), $hasher->asset('configs/a.json', '{}'));
        $this->assertSame($hasher->asset('a.json', '{}'), $hasher->asset('b.json', '{}'));
        $this->assertNotSame($hasher->asset('a.js', '{}'), $hasher->asset('a.json', '{}'));
    }
}

