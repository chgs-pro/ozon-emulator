<?php

declare(strict_types=1);

namespace App\Cli;

use App\Feature\Token\Command\IssueToken\IssueTokenCommand;
use App\Feature\Token\Command\IssueToken\IssueTokenHandler as IssueToken;
use InvalidArgumentException;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

use function gmdate;
use function json_encode;
use function preg_match;
use function trim;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

final readonly class IssueTokenHandler implements HandlerInterface
{
    public function __construct(
        private IssueToken
    $handler)
    {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $clientId = trim((string) $runner->request()->param('clientId', ''));
        $ttl      = (string) $runner->request()->option('ttl-days', '30');
        if (preg_match('/^[0-9]{1,3}$/D', $ttl) !== 1) {
            $runner->io()->writeln('Token lifetime must be an integer from 1 to 365 days.', 'error');

            return Response::INVALID_INPUT;
        }
        try {
            $result = $this->handler->handle(new IssueTokenCommand($clientId, (int) $ttl));
        } catch (InvalidArgumentException $exception) {
            $runner->io()->writeln($exception->getMessage(), 'error');

            return Response::INVALID_INPUT;
        }
        $runner->io()->writeln(json_encode([
            'client_id'  => $result->clientId,
            'api_key'    => $result->apiKey,
            'expires_at' => gmdate(DATE_ATOM, $result->expiresAt),
        ], JSON_THROW_ON_ERROR));

        return Response::SUCCESS;
    }
}
