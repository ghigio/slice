<?php

namespace App\Command;

use App\Jobs\JobRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:evenings:purge', 'Cancella le serate 90 giorni dopo la loro data')]
final class PurgeEveningsCommand
{
    public function __construct(private readonly JobRunner $jobs)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln(sprintf('Serate cancellate: %d', $this->jobs->purge()));

        return 0;
    }
}
