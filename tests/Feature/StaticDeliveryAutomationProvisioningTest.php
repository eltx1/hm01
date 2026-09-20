<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StaticDeliveryAutomationProvisioningTest extends TestCase
{
    public function test_provisioning_is_private_idempotent_and_supports_a_real_dry_run(): void
    {
        $directory = sys_get_temp_dir().'/horus-automation-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $environment = "APP_NAME=Horus\nHORUS_STATIC_DELIVERY_DRIVER=external-pages-sync\n";
        file_put_contents($directory.'/.env', $environment);
        $token = 'test-persistent-token-never-print-this';
        try {
            $command = [PHP_BINARY, base_path('scripts/configure-static-delivery-automation.php'), '--env='.$directory.'/.env', '--account='.str_repeat('a', 32), '--project=horus-media-cdn'];
            $dry = new Process($command);
            $dry->setInput($token)->mustRun();
            $this->assertSame($environment, file_get_contents($directory.'/.env'));
            $this->assertDirectoryDoesNotExist($directory.'/secrets');
            $this->assertStringNotContainsString($token, $dry->getOutput().$dry->getErrorOutput());

            $apply = new Process([...$command, '--apply']);
            $apply->setInput($token)->mustRun();
            $first = file_get_contents($directory.'/.env');
            $apply->setInput($token)->mustRun();
            $this->assertSame($first, file_get_contents($directory.'/.env'));
            $this->assertStringContainsString('HORUS_STATIC_DELIVERY_DRIVER=cloudflare-pages-direct', $first);
            $this->assertStringContainsString('HORUS_STATIC_DELIVERY_BATCH_INTERVAL_MINUTES=5', $first);
            $this->assertStringContainsString('APP_NAME=Horus', $first);
            $this->assertStringNotContainsString($token, $first.$apply->getOutput().$apply->getErrorOutput());
            $this->assertSame($token, trim(file_get_contents($directory.'/secrets/edge-cloudflare-token')));
            $this->assertSame(0600, fileperms($directory.'/secrets/edge-cloudflare-token') & 0777);
            $this->assertStringNotContainsString($token, file_get_contents($directory.'/static-delivery-automation.log'));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
