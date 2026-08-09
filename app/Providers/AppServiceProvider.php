<?php

namespace App\Providers;

use App\Services\ControlPlane\ActionCenter;
use App\Services\ControlPlane\Actions\FinanceActions;
use App\Services\ControlPlane\Actions\IntegrationActions;
use App\Services\ControlPlane\Actions\ReviewActions;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamOfficialSoapTransport;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\Network\SystemDnsResolver;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Drivers\CloudflarePagesPipelineDriver;
use App\Services\StaticDelivery\Drivers\LocalFilesystemStaticDeliveryDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([
            ReviewActions::class,
            IntegrationActions::class,
            FinanceActions::class,
        ], ActionCenterProvider::class);
        $this->app->singleton(ActionCenter::class, fn ($app): ActionCenter => new ActionCenter($app->tagged(ActionCenterProvider::class)));

        $this->app->bind(GamSoapTransportInterface::class, GamOfficialSoapTransport::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(StaticDeliveryDriverInterface::class, function ($app): StaticDeliveryDriverInterface {
            return match (config('static-delivery.driver')) {
                'cloudflare-pages-pipeline' => $app->make(CloudflarePagesPipelineDriver::class),
                'local' => $app->make(LocalFilesystemStaticDeliveryDriver::class),
                default => throw new \RuntimeException('Unsupported static delivery driver.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(str($request->input('email'))->lower().'|'.$request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(10)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()));
        RateLimiter::for('ads-txt-verification', fn (Request $request) => [
            Limit::perMinute(2)->by(($request->user()?->id ?: 'guest').'|'.(is_object($request->route('site')) ? $request->route('site')->getKey() : (string) $request->route('site')).'|'.$request->ip()),
            Limit::perHour(20)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()),
        ]);
    }
}
