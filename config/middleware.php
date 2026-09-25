<?php

declare(strict_types=1);

use App\Http\Middleware\ApiTokenMiddleware;
use App\Http\Middleware\SellerJsonMiddleware;
use App\Http\Middleware\SellerTokenMiddleware;
use PhpSoftBox\Application\Application;
use PhpSoftBox\Application\Middleware\BodyParserMiddleware;

return static function (Application $app): void {
    $app->alias('ozon.token', ApiTokenMiddleware::class);
    $app->alias('ozon.seller', SellerTokenMiddleware::class);
    $app->add(SellerJsonMiddleware::class, 100);
    $app->add(BodyParserMiddleware::class);
};
