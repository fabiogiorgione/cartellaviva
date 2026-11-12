<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pratiche, fase 2b: promemoria del rinnovo collegato alla pratica (ISEE al 31 dicembre).
 */
final class Version20261112130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pratiche: scadenza del rinnovo collegata alla pratica';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pratica ADD scadenza_rinnovo_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE pratica ADD CONSTRAINT FK_448253AC92EA2095 FOREIGN KEY (scadenza_rinnovo_id) REFERENCES scadenza (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_448253AC92EA2095 ON pratica (scadenza_rinnovo_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pratica DROP CONSTRAINT FK_448253AC92EA2095');
        $this->addSql('DROP INDEX IDX_448253AC92EA2095');
        $this->addSql('ALTER TABLE pratica DROP scadenza_rinnovo_id');
    }
}
