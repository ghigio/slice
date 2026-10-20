<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SqlitePragmaTest extends KernelTestCase
{
    public function testPragmaAttiviSuOgniConnessione(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame('wal', strtolower((string) $connection->fetchOne('PRAGMA journal_mode')));
    }
}
