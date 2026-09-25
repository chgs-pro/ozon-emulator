<?php

declare(strict_types=1);

namespace App\Runtime;

use PhpSoftBox\Application\Contracts\EnvironmentEnumInterface;
use PhpSoftBox\Env\EnvStorage;

use function strtolower;

enum Environment: string implements EnvironmentEnumInterface
{
    case DEV  = 'dev';
    case DEMO = 'demo';
    case TEST = 'test';
    case PROD = 'prod';

    public static function detect(): self
    {
        $value = EnvStorage::value('APP_ENV', self::DEV->value)->string(self::DEV->value)
            ?? self::DEV->value;

        return self::from(strtolower($value));
    }
}
