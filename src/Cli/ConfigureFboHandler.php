<?php

declare(strict_types=1);

namespace App\Cli;

use App\Feature\Fbo\Command\ConfigureCabinet\ConfigureCabinetCommand;
use App\Feature\Fbo\Command\ConfigureCabinet\ConfigureCabinetHandler;
use InvalidArgumentException;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use Throwable;

use function file_get_contents;
use function filesize;
use function is_array;
use function is_file;
use function is_readable;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final readonly class ConfigureFboHandler implements HandlerInterface
{
    public function __construct(
        private ConfigureCabinetHandler
    $handler)
    {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        try {
            $file = (string) $runner->request()->option('file', '');
            if (!is_file($file) || !is_readable($file) || filesize($file) > 2000000) {
                throw new InvalidArgumentException('Provide a readable JSON configuration file up to 2 MB');
            }
            $configuration = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($configuration)) {
                throw new InvalidArgumentException('JSON object required');
            }
            $version = $this->handler->handle(new ConfigureCabinetCommand((string) $runner->request()->param('clientId', ''), $configuration));
            $runner->io()->writeln(json_encode(['config_version' => $version], JSON_THROW_ON_ERROR));

            return Response::SUCCESS;
        } catch (Throwable $exception) {
            $runner->io()->writeln($exception->getMessage(), 'error');

            return Response::FAILURE;
        }
    }
}
