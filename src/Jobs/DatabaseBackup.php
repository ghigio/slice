<?php

namespace App\Jobs;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Copia coerente del database SQLite con VACUUM INTO, al massimo una al giorno; conserva le ultime 7 copie.
 * Usa una connessione propria, fuori da eventuali transazioni dell'ORM.
 */
final class DatabaseBackup
{
    public const int KEEP = 7;

    public function __construct(
        #[Autowire('%env(resolve:DATABASE_URL)%')] private readonly string $databaseUrl,
        #[Autowire('%app.backup_dir%')] private readonly string $backupDir,
    ) {
    }

    public function backup(\DateTimeImmutable $now): BackupResult
    {
        $params = (new DsnParser(['sqlite' => 'pdo_sqlite', 'sqlite3' => 'pdo_sqlite']))->parse($this->databaseUrl);
        if ('pdo_sqlite' !== ($params['driver'] ?? null)) {
            return new BackupResult(BackupOutcome::NotSqlite);
        }

        $file = sprintf('%s/app-%s.sqlite', $this->backupDir, $now->format('Y-m-d'));
        if (is_file($file)) {
            return new BackupResult(BackupOutcome::AlreadyDone, $file);
        }

        (new Filesystem())->mkdir($this->backupDir);
        $connection = DriverManager::getConnection($params);
        try {
            $connection->executeStatement('VACUUM INTO ?', [$file]);
        } finally {
            $connection->close();
        }

        $this->prune();

        return new BackupResult(BackupOutcome::Created, $file);
    }

    private function prune(): void
    {
        $files = glob($this->backupDir.'/app-*.sqlite') ?: [];
        sort($files);
        (new Filesystem())->remove(\array_slice($files, 0, max(0, \count($files) - self::KEEP)));
    }
}
