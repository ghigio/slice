<?php

namespace App\Jobs;

use Symfony\Component\Clock\ClockInterface;

/**
 * Job periodici (cancellazione serate scadute e backup), avviati dall'endpoint segreto o dai comandi console.
 */
final class JobRunner
{
    public function __construct(
        private readonly EveningPurger $purger,
        private readonly DatabaseBackup $backup,
        private readonly ClockInterface $clock,
    ) {
    }

    public function purge(): int
    {
        return $this->purger->purge($this->clock->now());
    }

    public function backup(): BackupResult
    {
        return $this->backup->backup($this->clock->now());
    }
}
