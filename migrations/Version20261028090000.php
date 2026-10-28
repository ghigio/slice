<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pizzerie note con ingredienti e menu, e pizzeria nota della serata. Scritta a mano: la migrazione generata da Doctrine
 * ricreava la tabella evenings con DROP TABLE, che con foreign_keys=ON cancellerebbe a cascata gruppi e pizze.
 */
final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pizzerie, ingredienti e menu (pizzerias, ingredients, menu_pizzas, menu_pizza_ingredients, evenings.pizzeria_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pizzerias (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, normalized_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_59A7D7CCD69C0128 ON pizzerias (normalized_name)');
        $this->addSql('CREATE TABLE ingredients (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(60) NOT NULL, normalized_name VARCHAR(60) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, pizzeria_id BIGINT NOT NULL, CONSTRAINT FK_4B60114FF1965E46 FOREIGN KEY (pizzeria_id) REFERENCES pizzerias (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingredients_pizzeria_name ON ingredients (pizzeria_id, normalized_name)');
        $this->addSql('CREATE INDEX IDX_4B60114FF1965E46 ON ingredients (pizzeria_id)');
        $this->addSql('CREATE TABLE menu_pizzas (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(80) NOT NULL, normalized_name VARCHAR(80) NOT NULL, price_cents INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, pizzeria_id BIGINT NOT NULL, CONSTRAINT FK_158B3698F1965E46 FOREIGN KEY (pizzeria_id) REFERENCES pizzerias (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_menu_pizzas_pizzeria_name ON menu_pizzas (pizzeria_id, normalized_name)');
        $this->addSql('CREATE INDEX IDX_158B3698F1965E46 ON menu_pizzas (pizzeria_id)');
        $this->addSql('CREATE TABLE menu_pizza_ingredients (menu_pizza_id BIGINT NOT NULL, ingredient_id BIGINT NOT NULL, PRIMARY KEY (menu_pizza_id, ingredient_id), CONSTRAINT FK_8039C485F84C58D4 FOREIGN KEY (menu_pizza_id) REFERENCES menu_pizzas (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_8039C485933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredients (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8039C485F84C58D4 ON menu_pizza_ingredients (menu_pizza_id)');
        $this->addSql('CREATE INDEX IDX_8039C485933FE08C ON menu_pizza_ingredients (ingredient_id)');

        // SQLite ammette ADD COLUMN con REFERENCES se il valore predefinito è NULL: nessuna tabella da ricreare.
        $this->addSql('ALTER TABLE evenings ADD COLUMN pizzeria_id BIGINT DEFAULT NULL REFERENCES pizzerias (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EB7D4027F1965E46 ON evenings (pizzeria_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_EB7D4027F1965E46');
        $this->addSql('ALTER TABLE evenings DROP COLUMN pizzeria_id');
        $this->addSql('DROP TABLE menu_pizza_ingredients');
        $this->addSql('DROP TABLE menu_pizzas');
        $this->addSql('DROP TABLE ingredients');
        $this->addSql('DROP TABLE pizzerias');
    }
}
