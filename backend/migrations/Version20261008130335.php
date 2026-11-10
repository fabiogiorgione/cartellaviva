<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008130335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anteprima dei file dell\'ente (risposte delle famiglie): chiave e tipo della copia';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ente_file ADD anteprima_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_file ADD anteprima_mime VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ente_file DROP anteprima_key');
        $this->addSql('ALTER TABLE ente_file DROP anteprima_mime');
    }
}
