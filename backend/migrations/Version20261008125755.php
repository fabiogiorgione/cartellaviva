<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008125755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anteprima dei documenti: stato, chiave e tipo della copia per la visualizzazione';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE documento ADD anteprima_stato VARCHAR(20) DEFAULT \'non_serve\' NOT NULL');
        $this->addSql('ALTER TABLE documento ADD anteprima_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE documento ADD anteprima_mime VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE documento ADD anteprima_errore VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE documento DROP anteprima_stato');
        $this->addSql('ALTER TABLE documento DROP anteprima_key');
        $this->addSql('ALTER TABLE documento DROP anteprima_mime');
        $this->addSql('ALTER TABLE documento DROP anteprima_errore');
    }
}
