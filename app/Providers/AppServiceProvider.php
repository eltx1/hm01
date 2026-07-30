<?php

namespace App\Providers;

use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamNativeSoapTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GamSoapTransportInterface::class, GamNativeSoapTransport::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(str($request->input('email'))->lower().'|'.$request->ip()));
        RateLimiter::for('operations', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()?->id ?: $request->ip())));
        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers()->symbols());
    }
}
