<?php

namespace Tests\Feature;

use App\Models\LoaderRelease;
use App\Services\Inventory\LoaderArchiveRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoaderArchiveRegistryTest extends TestCase
{
    public function test_archive_bytes_are_checked_and_configuration_contains_only_exact_public_identity(): void
    {
        $directory = storage_path('framework/testing/loader-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory.'/assets/loader');
        $original = public_path();
        try {
            app()->usePublicPath($directory);
            $runtime = 'sha256:'.str_repeat('a',64); $bytes = '/* '.$runtime.' */';
            $digest = hash('sha256', $bytes); $file = 'hm-loader.'.substr($digest,0,16).'.min.js';
            file_put_contents($directory.'/assets/loader/'.$file,$bytes);
            file_put_contents($directory.'/assets/loader/releases.json',json_encode([$digest => ['file'=>$file,'runtimeBuild'=>$runtime,'version'=>'2.0.0','protocol'=>2]]));
            $registry = new LoaderArchiveRegistry;
            $release = new LoaderRelease(['version'=>'2.0.0+'.substr($digest,0,16),'minified_path'=>'runtime/loader/'.$file,'checksum'=>$digest]);
            $public = $registry->configuration($release,3);
            $this->assertSame('2.0.0',$public['version']);
            $this->assertSame($runtime,$public['runtimeBuild']);
            $this->assertSame('sha256-'.base64_encode(hex2bin($digest)),$public['integrity']);
            $this->assertArrayHasKey('runtime/loader/'.$file,$registry->assets());
            file_put_contents($directory.'/assets/loader/'.$file,'modified');
            $this->expectException(ValidationException::class);
            (new LoaderArchiveRegistry)->records();
        } finally { app()->usePublicPath($original); File::deleteDirectory($directory); }
    }
}
