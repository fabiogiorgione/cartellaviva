<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002135644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE condivisione_esterna (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, codice_hash VARCHAR(255) NOT NULL, nota VARCHAR(120) DEFAULT NULL, scade_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, max_download INT NOT NULL, download INT NOT NULL, tentativi_errati INT NOT NULL, sessione_hash VARCHAR(64) DEFAULT NULL, sessione_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, primo_accesso_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ultimo_accesso_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revocata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, owner_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_condivisione_scadenza ON condivisione_esterna (scade_il)');
        $this->addSql('CREATE UNIQUE INDEX uniq_condivisione_token ON condivisione_esterna (token_hash)');
        $this->addSql('CREATE INDEX IDX_5D6EBE7B9033212A ON condivisione_esterna (tenant_id)');
        $this->addSql('CREATE INDEX IDX_5D6EBE7B2B18554A ON condivisione_esterna (owner_user_id)');
        $this->addSql('CREATE TABLE condivisione_esterna_documento (condivisione_esterna_id UUID NOT NULL, documento_id UUID NOT NULL, PRIMARY KEY (condivisione_esterna_id, documento_id))');
        $this->addSql('CREATE INDEX IDX_F241D044EEA98DB5 ON condivisione_esterna_documento (condivisione_esterna_id)');
        $this->addSql('CREATE INDEX IDX_F241D04445C0CF75 ON condivisione_esterna_documento (documento_id)');
        $this->addSql('ALTER TABLE condivisione_esterna ADD CONSTRAINT FK_5D6EBE7B9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE condivisione_esterna ADD CONSTRAINT FK_5D6EBE7B2B18554A FOREIGN KEY (owner_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE condivisione_esterna_documento ADD CONSTRAINT FK_F241D044EEA98DB5 FOREIGN KEY (condivisione_esterna_id) REFERENCES condivisione_esterna (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE condivisione_esterna_documento ADD CONSTRAINT FK_F241D04445C0CF75 FOREIGN KEY (documento_id) REFERENCES documento (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE condivisione_esterna DROP CONSTRAINT FK_5D6EBE7B9033212A');
        $this->addSql('ALTER TABLE condivisione_esterna DROP CONSTRAINT FK_5D6EBE7B2B18554A');
        $this->addSql('ALTER TABLE condivisione_esterna_documento DROP CONSTRAINT FK_F241D044EEA98DB5');
        $this->addSql('ALTER TABLE condivisione_esterna_documento DROP CONSTRAINT FK_F241D04445C0CF75');
        $this->addSql('DROP TABLE condivisione_esterna');
        $this->addSql('DROP TABLE condivisione_esterna_documento');
    }
}
