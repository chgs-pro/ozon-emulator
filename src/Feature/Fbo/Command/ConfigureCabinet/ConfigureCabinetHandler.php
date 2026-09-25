<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\ConfigureCabinet;

use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\CabinetState;
use App\Http\Request\CabinetConfigurationSchema;

final readonly class ConfigureCabinetHandler
{
    public function __construct(
        private CabinetRepositoryInterface
    $cabinets,
    ) {
    }

    public function handle(ConfigureCabinetCommand $command): int
    {
        $schema = new CabinetConfigurationSchema(['clientId' => $command->clientId, 'configuration' => $command->configuration]);

        $schema->validate();
        $configuration = $schema->getArray('configuration');

        return $this->cabinets->change($schema->getString('clientId'), static function (CabinetState $state) use ($configuration): int {
            if ($state->data['config'] !== $configuration) {
                $state->data['config'] = $configuration;
                ++$state->data['config_version'];
                $state->data['config_history'][(string) $state->data['config_version']] = $configuration;
            }

            return $state->data['config_version'];
        });
    }
}
