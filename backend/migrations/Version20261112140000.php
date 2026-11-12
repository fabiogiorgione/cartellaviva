<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pratiche, fase 2c: dove si analizzano i documenti (server o iPhone).
 */
final class Version20261112140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pratiche: colonna analisi_su (server o telefono)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pratica ADD analisi_su VARCHAR(10) DEFAULT \'server\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pratica DROP analisi_su');
    }
}
