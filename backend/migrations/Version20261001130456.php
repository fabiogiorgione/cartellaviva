<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261001130456 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tenant_export (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scade_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scaricato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tenant_id UUID NOT NULL, richiesto_da_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_export_token ON tenant_export (token_hash)');
        $this->addSql('CREATE INDEX IDX_632772CD9033212A ON tenant_export (tenant_id)');
        $this->addSql('CREATE INDEX IDX_632772CDCD8868C1 ON tenant_export (richiesto_da_id)');
        $this->addSql('ALTER TABLE tenant_export ADD CONSTRAINT FK_632772CD9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tenant_export ADD CONSTRAINT FK_632772CDCD8868C1 FOREIGN KEY (richiesto_da_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_source ADD oauth_ritorno VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD modalita_avanzata BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE tenant ADD eliminazione_richiesta_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD eliminazione_programmata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD eliminazione_richiesta_da_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD CONSTRAINT FK_4E59C462B6F30B03 FOREIGN KEY (eliminazione_richiesta_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_4E59C462B6F30B03 ON tenant (eliminazione_richiesta_da_id)');
        // Chi ha già ruoli personalizzati resta in modalità avanzata (altrimenti i ruoli sparirebbero).
        $this->addSql('UPDATE tenant SET modalita_avanzata = true WHERE id IN (SELECT DISTINCT tenant_id FROM role WHERE tenant_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tenant_export DROP CONSTRAINT FK_632772CD9033212A');
        $this->addSql('ALTER TABLE tenant_export DROP CONSTRAINT FK_632772CDCD8868C1');
        $this->addSql('DROP TABLE tenant_export');
        $this->addSql('ALTER TABLE import_source DROP oauth_ritorno');
        $this->addSql('ALTER TABLE tenant DROP CONSTRAINT FK_4E59C462B6F30B03');
        $this->addSql('DROP INDEX IDX_4E59C462B6F30B03');
        $this->addSql('ALTER TABLE tenant DROP modalita_avanzata');
        $this->addSql('ALTER TABLE tenant DROP eliminazione_richiesta_il');
        $this->addSql('ALTER TABLE tenant DROP eliminazione_programmata_il');
        $this->addSql('ALTER TABLE tenant DROP eliminazione_richiesta_da_id');
    }
}
