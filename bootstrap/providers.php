<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\CourierTrackingServiceProvider;
use App\Providers\FinanceServiceProvider;
use App\Providers\FulfilmentServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\PurchasingServiceProvider;
use App\Providers\ReturnsServiceProvider;
use App\Providers\RiskServiceProvider;
use App\Providers\SearchServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    InventoryServiceProvider::class,
    FinanceServiceProvider::class,
    FulfilmentServiceProvider::class,
    CourierTrackingServiceProvider::class,
    NotificationServiceProvider::class,
    PurchasingServiceProvider::class,
    ReturnsServiceProvider::class,
    RiskServiceProvider::class,
    SearchServiceProvider::class,
];
