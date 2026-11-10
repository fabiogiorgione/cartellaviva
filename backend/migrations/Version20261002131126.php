<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002131126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE token_account (id UUID NOT NULL, tipo VARCHAR(20) NOT NULL, token_hash VARCHAR(64) NOT NULL, nuova_email VARCHAR(180) DEFAULT NULL, scadenza TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, usato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_token_account_user ON token_account (user_id, tipo, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_token_account_hash ON token_account (token_hash)');
        $this->addSql('CREATE INDEX IDX_93394F27A76ED395 ON token_account (user_id)');
        $this->addSql('ALTER TABLE token_account ADD CONSTRAINT FK_93394F27A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_user ADD email_verificata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD credenziali_cambiate_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Gli account già esistenti restano validi: si considerano verificati.
        $this->addSql('UPDATE app_user SET email_verificata_il = created_at');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE token_account DROP CONSTRAINT FK_93394F27A76ED395');
        $this->addSql('DROP TABLE token_account');
        $this->addSql('ALTER TABLE app_user DROP email_verificata_il');
        $this->addSql('ALTER TABLE app_user DROP credenziali_cambiate_il');
    }
}
