<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prezzo della pizza facoltativo. SQLite ricrea la tabella pizzas: è sicuro perché nessuna tabella dipende da pizzas
 * (nessuna cancellazione a cascata) e i dati vengono copiati. Il down fallisce se esistono pizze senza prezzo.
 */
final class Version20261026162634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prezzo della pizza facoltativo (pizzas.price_cents nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__pizzas AS SELECT id, person, pizza_name, normalized_name, price_cents, created_at, updated_at, eater_id FROM pizzas');
        $this->addSql('DROP TABLE pizzas');
        $this->addSql('CREATE TABLE pizzas (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, person VARCHAR(60) NOT NULL, pizza_name VARCHAR(80) NOT NULL, normalized_name VARCHAR(80) NOT NULL, price_cents INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, eater_id BIGINT NOT NULL, CONSTRAINT FK_C6CC6E4049BFF538 FOREIGN KEY (eater_id) REFERENCES eaters (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO pizzas (id, person, pizza_name, normalized_name, price_cents, created_at, updated_at, eater_id) SELECT id, person, pizza_name, normalized_name, price_cents, created_at, updated_at, eater_id FROM __temp__pizzas');
        $this->addSql('DROP TABLE __temp__pizzas');
        $this->addSql('CREATE INDEX IDX_C6CC6E4049BFF538 ON pizzas (eater_id)');
        $this->addSql('CREATE INDEX idx_pizzas_normalized_name ON pizzas (normalized_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__pizzas AS SELECT id, person, pizza_name, normalized_name, price_cents, created_at, updated_at, eater_id FROM pizzas');
        $this->addSql('DROP TABLE pizzas');
        $this->addSql('CREATE TABLE pizzas (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, person VARCHAR(60) NOT NULL, pizza_name VARCHAR(80) NOT NULL, normalized_name VARCHAR(80) NOT NULL, price_cents INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, eater_id BIGINT NOT NULL, CONSTRAINT FK_C6CC6E4049BFF538 FOREIGN KEY (eater_id) REFERENCES eaters (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO pizzas (id, person, pizza_name, normalized_name, price_cents, created_at, updated_at, eater_id) SELECT id, person, pizza_name, normalized_name, price_cents, created_at, updated_at, eater_id FROM __temp__pizzas');
        $this->addSql('DROP TABLE __temp__pizzas');
        $this->addSql('CREATE INDEX idx_pizzas_normalized_name ON pizzas (normalized_name)');
        $this->addSql('CREATE INDEX IDX_C6CC6E4049BFF538 ON pizzas (eater_id)');
    }
}
