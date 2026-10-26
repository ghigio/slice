<?php

namespace App\Command;

use App\Jobs\JobRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:db:backup', 'Copia giornaliera del database SQLite in var/backups (ultime 7 copie)')]
final class BackupDatabaseCommand
{
    public function __construct(private readonly JobRunner $jobs)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln('Backup: '.$this->jobs->backup()->describe());

        return 0;
    }
}
