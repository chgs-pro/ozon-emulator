<?php

declare(strict_types=1);

namespace App\Cli;

use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

use function PhpSoftBox\CliApp\arg;
use function PhpSoftBox\CliApp\opt;

final class AppCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'app:health',
            description: 'Check application bootstrap',
            signature: [],
            handler: HealthHandler::class,
        ));
        $registry->register(Command::define(
            name: 'ozon:token:issue',
            description: 'Issue a local Ozon API key (printed once)',
            signature: [arg('clientId', 'Test client ID'), opt('ttl-days', null, 'Lifetime in days', default: '30')],
            handler: IssueTokenHandler::class,
        ));
        $registry->register(Command::define(
            name: 'ozon:fbo:control',
            description: 'Inspect journal or apply an external event to one local cabinet',
            signature: [arg('clientId', 'Test client ID'), opt('file', null, 'JSON control event; omit to inspect journal')],
            handler: ControlFboHandler::class,
        ));
        $registry->register(Command::define(
            name: 'ozon:fbo:configure',
            description: 'Load test cabinet configuration without creating supplies',
            signature: [arg('clientId', 'Test client ID'), opt('file', null, 'JSON configuration file')],
            handler: ConfigureFboHandler::class,
        ));
        $registry->register(Command::define(
            name: 'ozon:fbs:generate',
            description: 'Create random unfulfilled FBS postings: --batch every --every minutes until --total',
            signature: [arg('clientId', 'Test client ID'), opt('batch', null, 'Postings per run (1-100)', default: '1'), opt('total', null, 'Postings to create in total (1-10000)', default: '1'),
                opt('every', null, 'Minutes between runs (0-1440); 0 creates all at once', default: '0'), opt('seed', null, 'Random seed for a reproducible assortment')],
            handler: GenerateFbsPostingsHandler::class,
        ));
    }
}
