<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use PhpSoftBox\CsFixer\Console\AbstractCsFixerHandler;

return new class () extends AbstractCsFixerHandler {
    protected function getFinder(): Finder
    {
        return Finder::create()
            ->in([
                __DIR__ . '/../config',
                __DIR__ . '/../src',
                __DIR__ . '/../tests',
            ])
            ->exclude('vendor')
            ->ignoreVCS(true)
            ->name('*.php');
    }
};
