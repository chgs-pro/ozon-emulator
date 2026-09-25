<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use App\Feature\Fbs\FbsConfig;

use function array_slice;

/** Bounded local test cabinet aggregate. A CAS publishes all related external facts together. */
final class CabinetState
{
    public function __construct(
        public array $data = [
    ],
    ) {
        $this->data += ['config' => [], 'config_version' => 0, 'drafts' => [], 'orders' => [], 'sequence' => 100000, 'events' => [], 'operations' => [], 'cargo' => [], 'bundles' => [], 'documents' => [], 'acts' => [], 'control_events' => []];
    }

    public function id(): int
    {
        return ++$this->data['sequence'];
    }

    public function config(): array
    {
        if ($this->data['config'] === []) {
            throw new SellerApiException('Test cabinet is not configured', 403, 7);
        }

        return $this->data['config'];
    }

    /** @param bool $fbs FBS methods need the FBS section of the cabinet, the other methods need FBO */
    public function authorize(bool $write, bool $fbs = false): void
    {
        $config  = $this->config();
        $enabled = $fbs ? FbsConfig::enabled($config) : $config['fboEnabled'];
        if (!$enabled || ($write && !$config['writeEnabled'])) {
            throw new SellerApiException('Access denied', 403, 7);
        }
    }

    public function event(string $action, int $time, array $ids = []): void
    {
        $this->data['events'][] = ['action' => $action, 'time' => $time, 'ids' => $ids];
        $this->data['events']   = array_slice($this->data['events'], -200);
    }
}
