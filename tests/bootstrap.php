<?php

declare(strict_types=1);

$_ENV['APP_ENV']    = 'test';
$_SERVER['APP_ENV'] = 'test';

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/bootstrap.php';
