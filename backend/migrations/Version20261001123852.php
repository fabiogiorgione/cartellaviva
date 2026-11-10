<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261001123852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE scadenza (id UUID NOT NULL, titolo VARCHAR(200) NOT NULL, note TEXT DEFAULT NULL, data DATE NOT NULL, ricorrenza VARCHAR(20) NOT NULL, promemoria_giorni INT DEFAULT NULL, promemoria_il DATE DEFAULT NULL, promemoria_inviato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, documento_rimosso BOOLEAN DEFAULT false NOT NULL, origine VARCHAR(20) NOT NULL, stato VARCHAR(20) NOT NULL, completata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, documento_id UUID DEFAULT NULL, membro_id UUID DEFAULT NULL, categoria_id UUID DEFAULT NULL, completata_da_id UUID DEFAULT NULL, tenant_id UUID NOT NULL, owner_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_scadenza_tenant_data ON scadenza (tenant_id, data)');
        $this->addSql('CREATE INDEX idx_scadenza_promemoria ON scadenza (stato, promemoria_il)');
        $this->addSql('CREATE INDEX IDX_F36D33B945C0CF75 ON scadenza (documento_id)');
        $this->addSql('CREATE INDEX IDX_F36D33B9524172E ON scadenza (membro_id)');
        $this->addSql('CREATE INDEX IDX_F36D33B93397707A ON scadenza (categoria_id)');
        $this->addSql('CREATE INDEX IDX_F36D33B95D1DE38E ON scadenza (completata_da_id)');
        $this->addSql('CREATE INDEX IDX_F36D33B99033212A ON scadenza (tenant_id)');
        $this->addSql('CREATE INDEX IDX_F36D33B92B18554A ON scadenza (owner_user_id)');
        $this->addSql('ALTER TABLE scadenza ADD CONSTRAINT FK_F36D33B945C0CF75 FOREIGN KEY (documento_id) REFERENCES documento (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scadenza ADD CONSTRAINT FK_F36D33B9524172E FOREIGN KEY (membro_id) REFERENCES membro_famiglia (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scadenza ADD CONSTRAINT FK_F36D33B93397707A FOREIGN KEY (categoria_id) REFERENCES categoria (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scadenza ADD CONSTRAINT FK_F36D33B95D1DE38E FOREIGN KEY (completata_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scadenza ADD CONSTRAINT FK_F36D33B99033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scadenza ADD CONSTRAINT FK_F36D33B92B18554A FOREIGN KEY (owner_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scadenza DROP CONSTRAINT FK_F36D33B945C0CF75');
        $this->addSql('ALTER TABLE scadenza DROP CONSTRAINT FK_F36D33B9524172E');
        $this->addSql('ALTER TABLE scadenza DROP CONSTRAINT FK_F36D33B93397707A');
        $this->addSql('ALTER TABLE scadenza DROP CONSTRAINT FK_F36D33B95D1DE38E');
        $this->addSql('ALTER TABLE scadenza DROP CONSTRAINT FK_F36D33B99033212A');
        $this->addSql('ALTER TABLE scadenza DROP CONSTRAINT FK_F36D33B92B18554A');
        $this->addSql('DROP TABLE scadenza');
    }
}
