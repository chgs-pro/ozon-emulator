<?php

declare(strict_types=1);

namespace App\Runtime;

use PhpSoftBox\Application\Contracts\EnvironmentEnumInterface;
use PhpSoftBox\Env\EnvStorage;
use RuntimeException;

use function array_keys;
use function array_map;
use function implode;
use function sprintf;
use function strtolower;
use function trim;

enum Environment: string implements EnvironmentEnumInterface
{
    case DEV  = 'dev';
    case DEMO = 'demo';
    case TEST = 'test';
    case PROD = 'prod';

    private const array ALIASES = [
        'production'  => 'prod',
        'development' => 'dev',
        'local'       => 'dev',
        'testing'     => 'test',
    ];

    /**
     * Окружение из `APP_ENV` (по умолчанию `dev`). Распространённые полные имена приводятся к коротким
     * (`production` → `prod`), неизвестное значение — понятная ошибка конфигурации, а не ValueError на каждом запросе.
     */
    public static function detect(): self
    {
        $value = strtolower(trim(EnvStorage::value('APP_ENV', self::DEV->value)->string(self::DEV->value) ?? self::DEV->value));
        $value = self::ALIASES[$value] ?? $value;

        $environment = self::tryFrom($value);
        if ($environment === null) {
            throw new RuntimeException(sprintf(
                'Unknown APP_ENV "%s". Allowed: %s (aliases: %s).',
                $value,
                implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())),
                implode(', ', array_keys(self::ALIASES)),
            ));
        }

        return $environment;
    }
}
