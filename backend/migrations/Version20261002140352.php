<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002140352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dispositivo_push (id UUID NOT NULL, token VARCHAR(512) NOT NULL, piattaforma VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, aggiornato_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_dispositivo_token ON dispositivo_push (token)');
        $this->addSql('CREATE INDEX IDX_D9453A47A76ED395 ON dispositivo_push (user_id)');
        $this->addSql('ALTER TABLE dispositivo_push ADD CONSTRAINT FK_D9453A47A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dispositivo_push DROP CONSTRAINT FK_D9453A47A76ED395');
        $this->addSql('DROP TABLE dispositivo_push');
    }
}
