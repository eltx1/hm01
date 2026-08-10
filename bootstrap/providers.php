<?php

use App\Providers\AppServiceProvider;
use App\Providers\CampaignServiceProvider;
use App\Providers\ComplianceServiceProvider;
use App\Providers\DemandServiceProvider;
use App\Providers\MonetizationServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OperationsServiceProvider;
use App\Providers\PrebidServiceProvider;
use App\Providers\ReportingServiceProvider;
use App\Providers\SettingsServiceProvider;
use App\Providers\SupportServiceProvider;

return [
    AppServiceProvider::class,
    PrebidServiceProvider::class,
    CampaignServiceProvider::class,
    ComplianceServiceProvider::class,
    DemandServiceProvider::class,
    ReportingServiceProvider::class,
    OperationsServiceProvider::class,
    MonetizationServiceProvider::class,
    NotificationServiceProvider::class,
    SupportServiceProvider::class,
    SettingsServiceProvider::class,
];
