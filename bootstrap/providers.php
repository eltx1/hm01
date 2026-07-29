<?php

use App\Providers\AppServiceProvider;
use App\Providers\CampaignServiceProvider;
use App\Providers\DemandServiceProvider;
use App\Providers\PrebidServiceProvider;
use App\Providers\ReportingServiceProvider;

return [
    AppServiceProvider::class,
    PrebidServiceProvider::class,
    CampaignServiceProvider::class,
    DemandServiceProvider::class,
    ReportingServiceProvider::class,
];
