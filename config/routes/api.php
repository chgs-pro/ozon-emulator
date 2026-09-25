<?php

declare(strict_types=1);

use App\Feature\Fbo\Contract;
use App\Http\Action\HealthAction;
use App\Http\Action\IdentityAction;
use App\Http\Action\LabelDownloadAction;
use App\Http\Action\SellerAction;
use PhpSoftBox\Router\RouteCollector;

return static function (RouteCollector $routes): void {
    $routes->get('/documents/label', LabelDownloadAction::class)->name('documents.label');
    $routes->get('/health', HealthAction::class)->name('health');
    $routes->get('/test/v1/identity', IdentityAction::class)->middleware('ozon.token')->name('test.identity');
    foreach (new Contract()->paths() as $path) {
        $routes->post($path, SellerAction::class)->middleware('ozon.seller')->name('seller.' . str_replace('/', '.', trim($path, '/')));
    }
};
