<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Link d'invito delle serate. Scritta a mano: la migrazione generata da Doctrine ricreava la tabella evenings
 * con DROP TABLE, che con foreign_keys=ON cancellerebbe a cascata gruppi e pizze.
 */
final class Version20261026155219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Link d'invito delle serate (evenings.invite_token)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evenings ADD COLUMN invite_token VARCHAR(64) DEFAULT NULL');

        // Le serate esistenti ricevono un token casuale, come quelle nuove.
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM evenings') as $id) {
            $this->addSql('UPDATE evenings SET invite_token = ? WHERE id = ?', [bin2hex(random_bytes(32)), $id]);
        }

        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB7D40275242FFC4 ON evenings (invite_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_EB7D40275242FFC4');
        $this->addSql('ALTER TABLE evenings DROP COLUMN invite_token');
    }
}
