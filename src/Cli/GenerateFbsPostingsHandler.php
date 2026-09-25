<?php

declare(strict_types=1);

namespace App\Cli;

use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\CabinetState;
use App\Feature\Fbs\FbsPostingGenerator;
use InvalidArgumentException;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use Psr\Clock\ClockInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

use function count;
use function ctype_digit;
use function json_encode;
use function min;
use function preg_match;
use function sleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Creates random FBS postings in one test cabinet: `--batch` postings every `--every` minutes until `--total`
 * are created. `--every=0` creates everything at once. `--seed` makes the assortment choice reproducible.
 * Postings are created only from published FBS free stock; without stock a run creates nothing and says so.
 */
final readonly class GenerateFbsPostingsHandler implements HandlerInterface
{
    public function __construct(
        private CabinetRepositoryInterface $cabinets,
        private FbsPostingGenerator $generator,
        private ClockInterface $clock,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        try {
            $clientId = (string) $runner->request()->param('clientId', '');
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $clientId) !== 1) {
                throw new InvalidArgumentException('Invalid Client ID');
            }
            $batch   = $this->option($runner, 'batch', 1, 100);
            $total   = $this->option($runner, 'total', 1, 10000);
            $every   = $this->option($runner, 'every', 0, 1440);
            $seed    = (string) $runner->request()->option('seed', '');
            $random  = new Randomizer($seed === '' ? null : new Mt19937((int) $seed));
            $created = 0;
            while ($created < $total) {
                $count   = min($batch, $total - $created);
                $numbers = $this->cabinets->change($clientId, fn (CabinetState $state): array => $this->generator->generate($state, $count, $this->clock->now()->getTimestamp() + ($state->config()['clockOffsetSeconds'] ?? 0), $random));
                $created += count($numbers);
                $runner->io()->writeln(json_encode(['created' => $numbers, 'total_created' => $created, 'total' => $total], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if (count($numbers) < $count) {
                    // Not an error: WMS may publish stock later; a periodic run waits for the next tick, a one-shot run stops.
                    $runner->io()->writeln($numbers === [] ? 'Нет опубликованного остатка — отправления не созданы' : 'Опубликованный остаток исчерпан — создано ' . count($numbers) . ' из ' . $count, 'comment');
                    if ($every === 0) {
                        break;
                    }
                }
                if ($created < $total && $every > 0) {
                    sleep($every * 60);
                }
            }

            return Response::SUCCESS;
        } catch (Throwable $error) {
            $runner->io()->writeln($error->getMessage(), 'error');

            return Response::FAILURE;
        }
    }

    private function option(RunnerInterface $runner, string $name, int $min, int $max): int
    {
        $value = (string) $runner->request()->option($name, '');
        if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException('--' . $name . ' must be an integer from ' . $min . ' to ' . $max);
        }

        return (int) $value;
    }
}
