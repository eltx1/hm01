<?php

namespace App\Providers;

use App\Models\GamApiOperation;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentProfile;
use App\Models\PublisherStatement;
use App\Models\ReconciliationRun;
use App\Models\ReportImportJob;
use App\Models\StaticDeliveryBatch;
use App\Observers\OperationalFailureObserver;
use App\Observers\PublisherPaymentObserver;
use App\Observers\PublisherPaymentProfileObserver;
use App\Observers\PublisherStatementObserver;
use App\Observers\ReconciliationRunObserver;
use App\Services\ControlPlane\ActionCenter;
use App\Services\ControlPlane\Actions\FinanceActions;
use App\Services\ControlPlane\Actions\IntegrationActions;
use App\Services\ControlPlane\Actions\MonetizationActions;
use App\Services\ControlPlane\Actions\PublisherActions;
use App\Services\ControlPlane\Actions\ReviewActions;
use App\Services\ControlPlane\Actions\SupportActions;
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
            MonetizationActions::class,
            FinanceActions::class,
            SupportActions::class,
            PublisherActions::class,
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
        PublisherPayment::observe(PublisherPaymentObserver::class);
        PublisherPaymentProfile::observe(PublisherPaymentProfileObserver::class);
        PublisherStatement::observe(PublisherStatementObserver::class);
        ReconciliationRun::observe(ReconciliationRunObserver::class);
        ReportImportJob::observe(OperationalFailureObserver::class);
        StaticDeliveryBatch::observe(OperationalFailureObserver::class);
        GamApiOperation::observe(OperationalFailureObserver::class);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(str($request->input('email'))->lower().'|'.$request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(10)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()));
        RateLimiter::for('privacy-diagnostic-run', fn (Request $request) => [
            Limit::perMinute(3)->by(($request->user()?->id ?: 'guest').'|'.(is_object($request->route('site')) ? $request->route('site')->getKey() : (string) $request->route('site'))),
            Limit::perHour(20)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()),
        ]);
        RateLimiter::for('privacy-diagnostic-report', fn (Request $request) => [
            Limit::perMinute(10)->by(strtolower((string) parse_url((string) $request->header('Origin'), PHP_URL_HOST))),
            Limit::perHour(120)->by($request->ip()),
        ]);
        RateLimiter::for('ads-txt-verification', fn (Request $request) => [
            Limit::perMinute(2)->by(($request->user()?->id ?: 'guest').'|'.(is_object($request->route('site')) ? $request->route('site')->getKey() : (string) $request->route('site')).'|'.$request->ip()),
            Limit::perHour(20)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()),
        ]);
        RateLimiter::for('support-create', fn (Request $request) => [
            Limit::perHour(10)->by(($request->user()?->organization_id ?: 'guest').'|'.($request->user()?->id ?: $request->ip())),
        ]);
        RateLimiter::for('support-reply', fn (Request $request) => [
            Limit::perMinute(6)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()),
            Limit::perHour(60)->by(($request->user()?->organization_id ?: 'guest').'|'.($request->user()?->id ?: $request->ip())),
        ]);
        RateLimiter::for('support-status', fn (Request $request) => Limit::perMinute(20)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()));
        RateLimiter::for('support-attachment', fn (Request $request) => Limit::perMinute(30)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()));
    }
}
