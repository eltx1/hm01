<?php

namespace App\Console\Commands;

use App\Services\Gam\GamReportingGoogleApp;
use App\Services\Gam\GamSecretResolver;
use Illuminate\Console\Command;
use Throwable;

class ConfigureGamReportingGoogle extends Command
{
    protected $signature = 'gam:reporting-google {--file= : Private Google Web application file to install}';

    protected $description = 'Check or provision platform Google sign-in without involving website administrators';

    public function handle(GamReportingGoogleApp $google, GamSecretResolver $resolver): int
    {
        if ($file = $this->option('file')) {
            try {
                $path = $resolver->resolveFile('file:'.$file);
                if (filesize($path) > 32768) {
                    throw new \RuntimeException;
                }
                $google->save($resolver->readJson('file:'.$file));
            } catch (Throwable) {
                $this->error('Google sign-in was not configured. Check the private Web application file and its reporting callback. No credential values were logged.');

                return self::FAILURE;
            }
        }
        $ready = $google->ready();
        $this->line($ready ? 'Platform Google application: configured.' : 'Platform Google application: not configured.');
        $this->line('Callback: '.$google->redirectUri());
        $this->line('This checks local configuration only. Google consent and network reporting access still need live verification.');

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
