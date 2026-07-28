<?php

namespace App\Providers;

use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamNativeSoapTransport;
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
        $this->app->bind(GamSoapTransportInterface::class, GamNativeSoapTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(str($request->input('email'))->lower().'|'.$request->ip()));
    }
}
