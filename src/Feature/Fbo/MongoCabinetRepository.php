<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use MongoDB\BSON\Document;
use MongoDB\Driver\Exception\BulkWriteException;
use PhpSoftBox\MongoDb\Connection\MongoConnectionManagerInterface;

use function strlen;

final readonly class MongoCabinetRepository implements CabinetRepositoryInterface
{
    public function __construct(
        private MongoConnectionManagerInterface
    $mongo)
    {
    }

    public function change(string $clientId, callable $operation): mixed
    {
        $collection = $this->mongo->collection('fbo_cabinets');
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $doc   = $collection->findOne(['_id' => $clientId], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
            $state = new CabinetState($doc['state'] ?? []);

            $before = $state->data;
            $result = $operation($state);
            if ($before === $state->data) {
                return $result;
            }
            $next = ['_id' => $clientId, 'revision' => ($doc['revision'] ?? 0) + 1, 'state' => $state->data];
            // A test aggregate has a documented capacity, never silent truncation at Mongo's 16 MB limit.
            if (strlen((string) Document::fromPHP($next)) > 12 * 1024 * 1024) {
                throw new SellerApiException('Local test cabinet capacity exceeded; use a separate test cabinet', 507, 8);
            }
            if ($doc === null) {
                try {
                    $collection->insertOne($next);

                    return $result;
                } catch (BulkWriteException $exception) {
                    if ($exception->getCode() !== 11000) {
                        throw $exception;
                    }
                }
            } elseif ($collection->replaceOne(['_id' => $clientId, 'revision' => $doc['revision']], $next)->getModifiedCount() === 1) {
                return $result;
            }
        }

        throw new SellerApiException('Concurrent update; retry the request', 409, 10);
    }
}
