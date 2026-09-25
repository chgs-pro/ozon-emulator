<?php

declare(strict_types=1);

namespace App\Cli;

use PhpSoftBox\Application\ApplicationEnvironment;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Config\Config;

final readonly class HealthHandler implements HandlerInterface
{
    public function __construct(
        private Config $config,
        private ApplicationEnvironment $environment,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $runner->io()->writeln('App is ready.');
        $runner->io()->writeln('env=' . $this->environment->value());
        $runner->io()->writeln('url=' . (string) $this->config->get('app.url', ''));

        return Response::SUCCESS;
    }
}
