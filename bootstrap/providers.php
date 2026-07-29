<?php

use App\Providers\AppServiceProvider;
use App\Providers\CampaignServiceProvider;
use App\Providers\DemandServiceProvider;
use App\Providers\PrebidServiceProvider;

return [
    AppServiceProvider::class,
    PrebidServiceProvider::class,
    CampaignServiceProvider::class,
    DemandServiceProvider::class,
];
