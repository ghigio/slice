<?php

namespace App\Tests\Integration\Jobs;

use App\Jobs\BackupOutcome;
use App\Jobs\DatabaseBackup;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DatabaseBackupTest extends TestCase
{
    private string $dir;
    private string $database;

    protected function setUp(): void
    {
        $this->dir = \dirname(__DIR__, 3).'/var/test-backup-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir.'/data');
        $this->database = $this->dir.'/data/app.db';

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->database]);
        $connection->executeStatement('CREATE TABLE evenings (name TEXT)');
        $connection->executeStatement("INSERT INTO evenings VALUES ('Pizza da Gino')");
        $connection->close();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testCreaUnaCopiaCoerenteUnaVoltaAlGiorno(): void
    {
        $backup = new DatabaseBackup('sqlite:///'.$this->database, $this->dir.'/backups');
        $now = new \DateTimeImmutable('2026-10-26 03:00');

        $result = $backup->backup($now);

        self::assertSame(BackupOutcome::Created, $result->outcome);
        self::assertSame('app-2026-10-26.sqlite', basename($result->file));
        $copy = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $result->file]);
        self::assertSame('Pizza da Gino', $copy->fetchOne('SELECT name FROM evenings'));
        $copy->close();

        self::assertSame(BackupOutcome::AlreadyDone, $backup->backup($now->modify('+5 hours'))->outcome);
    }

    public function testConservaLeUltimeSetteCopie(): void
    {
        $backups = $this->dir.'/backups';
        (new Filesystem())->mkdir($backups);
        for ($day = 10; $day <= 17; ++$day) {
            touch(sprintf('%s/app-2026-10-%02d.sqlite', $backups, $day));
        }

        (new DatabaseBackup('sqlite:///'.$this->database, $backups))->backup(new \DateTimeImmutable('2026-10-26'));

        $files = array_map('basename', glob($backups.'/app-*.sqlite'));
        sort($files);
        self::assertSame([
            'app-2026-10-12.sqlite', 'app-2026-10-13.sqlite', 'app-2026-10-14.sqlite', 'app-2026-10-15.sqlite',
            'app-2026-10-16.sqlite', 'app-2026-10-17.sqlite', 'app-2026-10-26.sqlite',
        ], $files);
    }

    public function testConMysqlNonServe(): void
    {
        $result = (new DatabaseBackup('mysql://u:p@db:3306/app?serverVersion=8.0', $this->dir.'/backups'))->backup(new \DateTimeImmutable());

        self::assertSame(BackupOutcome::NotSqlite, $result->outcome);
        self::assertNull($result->file);
    }
}
