<?php

declare(strict_types=1);

namespace App\Cli;

use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\Command\ControlCabinet\ControlCabinetCommand;
use App\Feature\Fbo\Command\ControlCabinet\ControlCabinetHandler;
use InvalidArgumentException;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use Throwable;

use function array_flip;
use function array_intersect_key;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_values;
use function file_get_contents;
use function filesize;
use function is_array;
use function is_file;
use function is_readable;
use function json_decode;
use function json_encode;
use function preg_match;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

final readonly class ControlFboHandler implements HandlerInterface
{
    public function __construct(
        private ControlCabinetHandler $handler,
        private CabinetRepositoryInterface $cabinets,
    ) {
    }
    public function run(RunnerInterface $runner): int|Response
    {
        try {
            $clientId = (string) $runner->request()->param('clientId', '');
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $clientId) !== 1) {
                throw new InvalidArgumentException('Invalid Client ID');
            }
            $file = (string) $runner->request()->option('file', '');
            if ($file === '') {
                $result = $this->cabinets->change($clientId, static fn (CabinetState $state): array => ['config_version' => $state->data['config_version'], 'order_ids' => array_keys($state->data['orders']), 'operations' => array_values(array_map(static fn (array $op): array => array_intersect_key($op, array_flip(['id', 'kind', 'order_id', 'status', 'error', 'created_at', 'ready_at', 'completed_at'])), $state->data['operations'])), 'events' => $state->data['events']]);
            } else {
                if (!is_file($file) || !is_readable($file) || filesize($file) > 2000000) {
                    throw new InvalidArgumentException('Provide a readable JSON event file up to 2 MB');
                }
                $event = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($event) || array_is_list($event)) {
                    throw new InvalidArgumentException('JSON object required');
                }
                $result = $this->handler->handle(new ControlCabinetCommand($clientId, $event));
            }
            $runner->io()->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return Response::SUCCESS;
        } catch (Throwable $error) {
            $runner->io()->writeln($error->getMessage(), 'error');

            return Response::FAILURE;
        }
    }
}
