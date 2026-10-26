<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le migrazioni non devono perdere dati: con foreign_keys=ON una tabella ricreata con DROP TABLE
 * cancellerebbe a cascata gruppi e pizze.
 */
final class MigrationsPreserveDataTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        $this->database = \dirname(__DIR__, 2).'/var/data/migrations-test.db';
        (new Filesystem())->remove(glob($this->database.'*'));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(glob($this->database.'*'));
    }

    public function testGruppiEPizzeSopravvivonoATutteLeMigrazioni(): void
    {
        $this->console('doctrine:migrations:migrate '.escapeshellarg('DoctrineMigrations\\Version20261020102900'));

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->database]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement("INSERT INTO evenings (admin_token, name, date, organizer_name, status, discount_mode, created_at, updated_at) VALUES ('".str_repeat('a', 64)."', 'Serata', '2026-10-19', 'Org', 'orders_open', 'proportional', '2026-10-19 20:00', '2026-10-19 20:00')");
        $connection->executeStatement("INSERT INTO eaters (evening_id, token, name, created_at, updated_at) VALUES (1, '".str_repeat('b', 64)."', 'Famiglia', '2026-10-19 20:00', '2026-10-19 20:00')");
        $connection->executeStatement("INSERT INTO pizzas (eater_id, person, pizza_name, normalized_name, price_cents, created_at, updated_at) VALUES (1, 'Anna', 'Margherita', 'margherita', 700, '2026-10-19 20:00', '2026-10-19 20:00')");
        $connection->close();

        $this->console('doctrine:migrations:migrate');

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->database]);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM eaters'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM pizzas'));
        self::assertSame(64, \strlen((string) $connection->fetchOne('SELECT invite_token FROM evenings')));
        $connection->close();
    }

    private function console(string $command): void
    {
        $root = \dirname(__DIR__, 2);
        exec(sprintf('DATABASE_URL=%s php %s/bin/console %s --env=test --no-interaction --quiet 2>&1', escapeshellarg('sqlite:///'.$this->database), escapeshellarg($root), $command), $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
