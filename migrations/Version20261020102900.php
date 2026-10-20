<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema iniziale (SQLite). Con MySQL le migrazioni vanno rigenerate.
 */
final class Version20261020102900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema iniziale: evenings, eaters, pizzas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE eaters (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, reimbursed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, evening_id BIGINT NOT NULL, CONSTRAINT FK_33ED64452277C05C FOREIGN KEY (evening_id) REFERENCES evenings (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_33ED64455F37A13B ON eaters (token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_eaters_evening_name ON eaters (evening_id, name)');
        $this->addSql('CREATE INDEX IDX_33ED64452277C05C ON eaters (evening_id)');
        $this->addSql('CREATE TABLE evenings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, admin_token VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, date DATE NOT NULL, pizzeria VARCHAR(255) DEFAULT NULL, organizer_name VARCHAR(255) NOT NULL, refund_notes CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, paid_total_cents INTEGER DEFAULT NULL, discount_mode VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, organizer_eater_id BIGINT DEFAULT NULL, CONSTRAINT FK_EB7D4027AB4EBE9B FOREIGN KEY (organizer_eater_id) REFERENCES eaters (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB7D4027FF1D5AC1 ON evenings (admin_token)');
        $this->addSql('CREATE INDEX IDX_EB7D4027AB4EBE9B ON evenings (organizer_eater_id)');
        $this->addSql('CREATE TABLE pizzas (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, person VARCHAR(60) NOT NULL, pizza_name VARCHAR(80) NOT NULL, normalized_name VARCHAR(80) NOT NULL, price_cents INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, eater_id BIGINT NOT NULL, CONSTRAINT FK_C6CC6E4049BFF538 FOREIGN KEY (eater_id) REFERENCES eaters (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_pizzas_normalized_name ON pizzas (normalized_name)');
        $this->addSql('CREATE INDEX IDX_C6CC6E4049BFF538 ON pizzas (eater_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pizzas');
        $this->addSql('DROP TABLE evenings');
        $this->addSql('DROP TABLE eaters');
    }
}
