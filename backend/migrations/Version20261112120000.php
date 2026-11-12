<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pratiche, fase 2a: motore comune (pratica, persone, voci con i valori
 * cifrati, documenti già analizzati) per il 730.
 */
final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pratiche: tabelle pratica, pratica_membro_famiglia, pratica_voce e pratica_analisi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pratica (id UUID NOT NULL, modello VARCHAR(20) NOT NULL, anno SMALLINT NOT NULL, stato VARCHAR(20) NOT NULL, consenso_cifrati_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, non_serve JSON NOT NULL, encrypted_dek TEXT NOT NULL, key_version INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, consenso_cifrati_da_id UUID DEFAULT NULL, tenant_id UUID NOT NULL, owner_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_pratica_tenant_modello ON pratica (tenant_id, modello, anno)');
        $this->addSql('CREATE INDEX IDX_448253ACB6C2310 ON pratica (consenso_cifrati_da_id)');
        $this->addSql('CREATE INDEX IDX_448253AC9033212A ON pratica (tenant_id)');
        $this->addSql('CREATE INDEX IDX_448253AC2B18554A ON pratica (owner_user_id)');
        $this->addSql('CREATE TABLE pratica_membro_famiglia (pratica_id UUID NOT NULL, membro_famiglia_id UUID NOT NULL, PRIMARY KEY (pratica_id, membro_famiglia_id))');
        $this->addSql('CREATE INDEX IDX_A42FC00C24038DEB ON pratica_membro_famiglia (pratica_id)');
        $this->addSql('CREATE INDEX IDX_A42FC00C19BD3F42 ON pratica_membro_famiglia (membro_famiglia_id)');
        $this->addSql('CREATE TABLE pratica_analisi (esito VARCHAR(10) NOT NULL, metodo VARCHAR(20) NOT NULL, analizzato_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, pratica_id UUID NOT NULL, documento_id UUID NOT NULL, PRIMARY KEY (pratica_id, documento_id))');
        $this->addSql('CREATE INDEX IDX_451748EB24038DEB ON pratica_analisi (pratica_id)');
        $this->addSql('CREATE INDEX IDX_451748EB45C0CF75 ON pratica_analisi (documento_id)');
        $this->addSql('CREATE TABLE pratica_voce (id UUID NOT NULL, requisito VARCHAR(60) NOT NULL, valori_cifrati TEXT NOT NULL, stato VARCHAR(20) NOT NULL, origine VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, pratica_id UUID NOT NULL, persona_id UUID DEFAULT NULL, documento_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_pratica_voce_pratica ON pratica_voce (pratica_id, stato)');
        $this->addSql('CREATE INDEX IDX_187DE6DD24038DEB ON pratica_voce (pratica_id)');
        $this->addSql('CREATE INDEX IDX_187DE6DDF5F88DB9 ON pratica_voce (persona_id)');
        $this->addSql('CREATE INDEX IDX_187DE6DD45C0CF75 ON pratica_voce (documento_id)');
        $this->addSql('ALTER TABLE pratica ADD CONSTRAINT FK_448253ACB6C2310 FOREIGN KEY (consenso_cifrati_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica ADD CONSTRAINT FK_448253AC9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica ADD CONSTRAINT FK_448253AC2B18554A FOREIGN KEY (owner_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica_membro_famiglia ADD CONSTRAINT FK_A42FC00C24038DEB FOREIGN KEY (pratica_id) REFERENCES pratica (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica_membro_famiglia ADD CONSTRAINT FK_A42FC00C19BD3F42 FOREIGN KEY (membro_famiglia_id) REFERENCES membro_famiglia (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica_analisi ADD CONSTRAINT FK_451748EB24038DEB FOREIGN KEY (pratica_id) REFERENCES pratica (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica_analisi ADD CONSTRAINT FK_451748EB45C0CF75 FOREIGN KEY (documento_id) REFERENCES documento (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica_voce ADD CONSTRAINT FK_187DE6DD24038DEB FOREIGN KEY (pratica_id) REFERENCES pratica (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica_voce ADD CONSTRAINT FK_187DE6DDF5F88DB9 FOREIGN KEY (persona_id) REFERENCES membro_famiglia (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica_voce ADD CONSTRAINT FK_187DE6DD45C0CF75 FOREIGN KEY (documento_id) REFERENCES documento (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pratica DROP CONSTRAINT FK_448253ACB6C2310');
        $this->addSql('ALTER TABLE pratica DROP CONSTRAINT FK_448253AC9033212A');
        $this->addSql('ALTER TABLE pratica DROP CONSTRAINT FK_448253AC2B18554A');
        $this->addSql('ALTER TABLE pratica_membro_famiglia DROP CONSTRAINT FK_A42FC00C24038DEB');
        $this->addSql('ALTER TABLE pratica_membro_famiglia DROP CONSTRAINT FK_A42FC00C19BD3F42');
        $this->addSql('ALTER TABLE pratica_analisi DROP CONSTRAINT FK_451748EB24038DEB');
        $this->addSql('ALTER TABLE pratica_analisi DROP CONSTRAINT FK_451748EB45C0CF75');
        $this->addSql('ALTER TABLE pratica_voce DROP CONSTRAINT FK_187DE6DD24038DEB');
        $this->addSql('ALTER TABLE pratica_voce DROP CONSTRAINT FK_187DE6DDF5F88DB9');
        $this->addSql('ALTER TABLE pratica_voce DROP CONSTRAINT FK_187DE6DD45C0CF75');
        $this->addSql('DROP TABLE pratica');
        $this->addSql('DROP TABLE pratica_membro_famiglia');
        $this->addSql('DROP TABLE pratica_analisi');
        $this->addSql('DROP TABLE pratica_voce');
    }
}
