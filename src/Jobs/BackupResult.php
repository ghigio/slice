<?php

namespace App\Jobs;

final readonly class BackupResult
{
    public function __construct(public BackupOutcome $outcome, public ?string $file = null)
    {
    }

    public function describe(): string
    {
        return match ($this->outcome) {
            BackupOutcome::Created => 'creato '.basename($this->file),
            BackupOutcome::AlreadyDone => 'già eseguito oggi ('.basename($this->file).')',
            BackupOutcome::NotSqlite => 'non necessario (database non SQLite)',
        };
    }
}
