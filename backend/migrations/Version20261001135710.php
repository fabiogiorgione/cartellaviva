<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261001135710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ente_anno (id UUID NOT NULL, nome VARCHAR(20) NOT NULL, archiviato BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ente_anno_nome ON ente_anno (tenant_id, nome)');
        $this->addSql('CREATE INDEX IDX_28611CAB9033212A ON ente_anno (tenant_id)');
        $this->addSql('CREATE TABLE ente_collegamento (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revocato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, famiglia_id UUID NOT NULL, ente_id UUID NOT NULL, referente_id UUID NOT NULL, persona_id UUID DEFAULT NULL, accettato_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ente_collegamento_referente ON ente_collegamento (referente_id)');
        $this->addSql('CREATE INDEX IDX_BC50550330D47B88 ON ente_collegamento (famiglia_id)');
        $this->addSql('CREATE INDEX IDX_BC505503EFB68F0A ON ente_collegamento (ente_id)');
        $this->addSql('CREATE INDEX IDX_BC505503F5F88DB9 ON ente_collegamento (persona_id)');
        $this->addSql('CREATE INDEX IDX_BC505503FB374F6F ON ente_collegamento (accettato_da_id)');
        $this->addSql('CREATE TABLE ente_file (id UUID NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, dimensione INT NOT NULL, nome_file VARCHAR(255) NOT NULL, encrypted_dek TEXT NOT NULL, key_version INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_621AB90B9033212A ON ente_file (tenant_id)');
        $this->addSql('CREATE TABLE ente_gruppo (id UUID NOT NULL, nome VARCHAR(80) NOT NULL, tipo VARCHAR(20) NOT NULL, tenant_id UUID NOT NULL, anno_id UUID NOT NULL, sede_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ente_gruppo_anno ON ente_gruppo (anno_id)');
        $this->addSql('CREATE INDEX IDX_932AB819033212A ON ente_gruppo (tenant_id)');
        $this->addSql('CREATE INDEX IDX_932AB81E19F41BF ON ente_gruppo (sede_id)');
        $this->addSql('CREATE TABLE ente_gruppo_operatore (gruppo_id UUID NOT NULL, membership_id UUID NOT NULL, PRIMARY KEY (gruppo_id, membership_id))');
        $this->addSql('CREATE INDEX IDX_568A18B869B87DB ON ente_gruppo_operatore (gruppo_id)');
        $this->addSql('CREATE INDEX IDX_568A18B1FB354CD ON ente_gruppo_operatore (membership_id)');
        $this->addSql('CREATE TABLE ente_iscritto (id UUID NOT NULL, nome VARCHAR(100) NOT NULL, cognome VARCHAR(100) NOT NULL, data_nascita DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ente_iscritto_nome ON ente_iscritto (tenant_id, cognome, nome)');
        $this->addSql('CREATE INDEX IDX_95CBF7789033212A ON ente_iscritto (tenant_id)');
        $this->addSql('CREATE TABLE ente_iscrizione (iscritto_id UUID NOT NULL, gruppo_id UUID NOT NULL, PRIMARY KEY (iscritto_id, gruppo_id))');
        $this->addSql('CREATE INDEX IDX_5E03F1A8AE71F7DF ON ente_iscrizione (iscritto_id)');
        $this->addSql('CREATE INDEX IDX_5E03F1A8869B87DB ON ente_iscrizione (gruppo_id)');
        $this->addSql('CREATE TABLE ente_profilo (id UUID NOT NULL, tipo VARCHAR(20) NOT NULL, codice_fiscale VARCHAR(16) NOT NULL, codice_meccanografico VARCHAR(10) DEFAULT NULL, numero_runts VARCHAR(30) DEFAULT NULL, email_istituzionale VARCHAR(180) NOT NULL, sede_legale VARCHAR(255) DEFAULT NULL, stato VARCHAR(30) NOT NULL, codice_hash VARCHAR(64) DEFAULT NULL, codice_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, codice_tentativi INT NOT NULL, email_confermata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, controlli JSON NOT NULL, nota_portale VARCHAR(1000) DEFAULT NULL, verificato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, verificato_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5038FC459033212A ON ente_profilo (tenant_id)');
        $this->addSql('CREATE INDEX IDX_5038FC45D0B8C2D2 ON ente_profilo (verificato_da_id)');
        $this->addSql('CREATE TABLE ente_referente (id UUID NOT NULL, nome VARCHAR(100) NOT NULL, cognome VARCHAR(100) NOT NULL, email VARCHAR(180) DEFAULT NULL, relazione VARCHAR(50) DEFAULT NULL, stato VARCHAR(20) NOT NULL, token_hash VARCHAR(64) DEFAULT NULL, token_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, invitato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, iscritto_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ente_referente_token ON ente_referente (token_hash)');
        $this->addSql('CREATE INDEX IDX_2E29CA72AE71F7DF ON ente_referente (iscritto_id)');
        $this->addSql('CREATE TABLE ente_richiesta (id UUID NOT NULL, tipo VARCHAR(20) NOT NULL, titolo VARCHAR(200) NOT NULL, istruzioni TEXT DEFAULT NULL, entro_il DATE DEFAULT NULL, firmatari VARCHAR(10) NOT NULL, annullata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, non_raggiunti JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, gruppo_id UUID DEFAULT NULL, allegato_id UUID DEFAULT NULL, creato_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ente_richiesta_tenant ON ente_richiesta (tenant_id, created_at)');
        $this->addSql('CREATE INDEX IDX_9383B1ED9033212A ON ente_richiesta (tenant_id)');
        $this->addSql('CREATE INDEX IDX_9383B1ED869B87DB ON ente_richiesta (gruppo_id)');
        $this->addSql('CREATE INDEX IDX_9383B1ED68F4D369 ON ente_richiesta (allegato_id)');
        $this->addSql('CREATE INDEX IDX_9383B1ED20958DFB ON ente_richiesta (creato_da_id)');
        $this->addSql('CREATE TABLE ente_richiesta_destinatario (id UUID NOT NULL, stato VARCHAR(20) NOT NULL, firmatari JSON NOT NULL, messaggio_famiglia VARCHAR(1000) DEFAULT NULL, motivo_ente VARCHAR(500) DEFAULT NULL, vista_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, risposta_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, verificata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sollecitata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, richiesta_id UUID NOT NULL, iscritto_id UUID NOT NULL, famiglia_id UUID NOT NULL, risposta_id UUID DEFAULT NULL, documento_famiglia_id UUID DEFAULT NULL, risposta_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_richiesta_dest_famiglia ON ente_richiesta_destinatario (famiglia_id, stato)');
        $this->addSql('CREATE UNIQUE INDEX uniq_richiesta_dest ON ente_richiesta_destinatario (richiesta_id, iscritto_id, famiglia_id)');
        $this->addSql('CREATE INDEX IDX_3F0319E6C8028080 ON ente_richiesta_destinatario (richiesta_id)');
        $this->addSql('CREATE INDEX IDX_3F0319E6AE71F7DF ON ente_richiesta_destinatario (iscritto_id)');
        $this->addSql('CREATE INDEX IDX_3F0319E630D47B88 ON ente_richiesta_destinatario (famiglia_id)');
        $this->addSql('CREATE INDEX IDX_3F0319E663C592CC ON ente_richiesta_destinatario (risposta_id)');
        $this->addSql('CREATE INDEX IDX_3F0319E6C4F50528 ON ente_richiesta_destinatario (documento_famiglia_id)');
        $this->addSql('CREATE INDEX IDX_3F0319E699C34053 ON ente_richiesta_destinatario (risposta_da_id)');
        $this->addSql('CREATE TABLE ente_richiesta_destinatario_referente (richiesta_destinatario_id UUID NOT NULL, referente_id UUID NOT NULL, PRIMARY KEY (richiesta_destinatario_id, referente_id))');
        $this->addSql('CREATE INDEX IDX_C8737DC3F575179 ON ente_richiesta_destinatario_referente (richiesta_destinatario_id)');
        $this->addSql('CREATE INDEX IDX_C8737DCC485953B ON ente_richiesta_destinatario_referente (referente_id)');
        $this->addSql('CREATE TABLE ente_sede (id UUID NOT NULL, nome VARCHAR(120) NOT NULL, indirizzo VARCHAR(255) DEFAULT NULL, tenant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C41E6DCA9033212A ON ente_sede (tenant_id)');
        $this->addSql('ALTER TABLE ente_anno ADD CONSTRAINT FK_28611CAB9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_collegamento ADD CONSTRAINT FK_BC50550330D47B88 FOREIGN KEY (famiglia_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_collegamento ADD CONSTRAINT FK_BC505503EFB68F0A FOREIGN KEY (ente_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_collegamento ADD CONSTRAINT FK_BC505503C485953B FOREIGN KEY (referente_id) REFERENCES ente_referente (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_collegamento ADD CONSTRAINT FK_BC505503F5F88DB9 FOREIGN KEY (persona_id) REFERENCES membro_famiglia (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_collegamento ADD CONSTRAINT FK_BC505503FB374F6F FOREIGN KEY (accettato_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_file ADD CONSTRAINT FK_621AB90B9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_gruppo ADD CONSTRAINT FK_932AB819033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_gruppo ADD CONSTRAINT FK_932AB815E14C45E FOREIGN KEY (anno_id) REFERENCES ente_anno (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_gruppo ADD CONSTRAINT FK_932AB81E19F41BF FOREIGN KEY (sede_id) REFERENCES ente_sede (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_gruppo_operatore ADD CONSTRAINT FK_568A18B869B87DB FOREIGN KEY (gruppo_id) REFERENCES ente_gruppo (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_gruppo_operatore ADD CONSTRAINT FK_568A18B1FB354CD FOREIGN KEY (membership_id) REFERENCES membership (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_iscritto ADD CONSTRAINT FK_95CBF7789033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_iscrizione ADD CONSTRAINT FK_5E03F1A8AE71F7DF FOREIGN KEY (iscritto_id) REFERENCES ente_iscritto (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_iscrizione ADD CONSTRAINT FK_5E03F1A8869B87DB FOREIGN KEY (gruppo_id) REFERENCES ente_gruppo (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_profilo ADD CONSTRAINT FK_5038FC459033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_profilo ADD CONSTRAINT FK_5038FC45D0B8C2D2 FOREIGN KEY (verificato_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_referente ADD CONSTRAINT FK_2E29CA72AE71F7DF FOREIGN KEY (iscritto_id) REFERENCES ente_iscritto (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta ADD CONSTRAINT FK_9383B1ED9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta ADD CONSTRAINT FK_9383B1ED869B87DB FOREIGN KEY (gruppo_id) REFERENCES ente_gruppo (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta ADD CONSTRAINT FK_9383B1ED68F4D369 FOREIGN KEY (allegato_id) REFERENCES ente_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta ADD CONSTRAINT FK_9383B1ED20958DFB FOREIGN KEY (creato_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E6C8028080 FOREIGN KEY (richiesta_id) REFERENCES ente_richiesta (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E6AE71F7DF FOREIGN KEY (iscritto_id) REFERENCES ente_iscritto (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E630D47B88 FOREIGN KEY (famiglia_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E663C592CC FOREIGN KEY (risposta_id) REFERENCES ente_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E6C4F50528 FOREIGN KEY (documento_famiglia_id) REFERENCES documento (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E699C34053 FOREIGN KEY (risposta_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario_referente ADD CONSTRAINT FK_C8737DC3F575179 FOREIGN KEY (richiesta_destinatario_id) REFERENCES ente_richiesta_destinatario (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario_referente ADD CONSTRAINT FK_C8737DCC485953B FOREIGN KEY (referente_id) REFERENCES ente_referente (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_sede ADD CONSTRAINT FK_C41E6DCA9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scadenza ADD richiesta_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE scadenza ADD CONSTRAINT FK_F36D33B9C8028080 FOREIGN KEY (richiesta_id) REFERENCES ente_richiesta_destinatario (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F36D33B9C8028080 ON scadenza (richiesta_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ente_anno DROP CONSTRAINT FK_28611CAB9033212A');
        $this->addSql('ALTER TABLE ente_collegamento DROP CONSTRAINT FK_BC50550330D47B88');
        $this->addSql('ALTER TABLE ente_collegamento DROP CONSTRAINT FK_BC505503EFB68F0A');
        $this->addSql('ALTER TABLE ente_collegamento DROP CONSTRAINT FK_BC505503C485953B');
        $this->addSql('ALTER TABLE ente_collegamento DROP CONSTRAINT FK_BC505503F5F88DB9');
        $this->addSql('ALTER TABLE ente_collegamento DROP CONSTRAINT FK_BC505503FB374F6F');
        $this->addSql('ALTER TABLE ente_file DROP CONSTRAINT FK_621AB90B9033212A');
        $this->addSql('ALTER TABLE ente_gruppo DROP CONSTRAINT FK_932AB819033212A');
        $this->addSql('ALTER TABLE ente_gruppo DROP CONSTRAINT FK_932AB815E14C45E');
        $this->addSql('ALTER TABLE ente_gruppo DROP CONSTRAINT FK_932AB81E19F41BF');
        $this->addSql('ALTER TABLE ente_gruppo_operatore DROP CONSTRAINT FK_568A18B869B87DB');
        $this->addSql('ALTER TABLE ente_gruppo_operatore DROP CONSTRAINT FK_568A18B1FB354CD');
        $this->addSql('ALTER TABLE ente_iscritto DROP CONSTRAINT FK_95CBF7789033212A');
        $this->addSql('ALTER TABLE ente_iscrizione DROP CONSTRAINT FK_5E03F1A8AE71F7DF');
        $this->addSql('ALTER TABLE ente_iscrizione DROP CONSTRAINT FK_5E03F1A8869B87DB');
        $this->addSql('ALTER TABLE ente_profilo DROP CONSTRAINT FK_5038FC459033212A');
        $this->addSql('ALTER TABLE ente_profilo DROP CONSTRAINT FK_5038FC45D0B8C2D2');
        $this->addSql('ALTER TABLE ente_referente DROP CONSTRAINT FK_2E29CA72AE71F7DF');
        $this->addSql('ALTER TABLE ente_richiesta DROP CONSTRAINT FK_9383B1ED9033212A');
        $this->addSql('ALTER TABLE ente_richiesta DROP CONSTRAINT FK_9383B1ED869B87DB');
        $this->addSql('ALTER TABLE ente_richiesta DROP CONSTRAINT FK_9383B1ED68F4D369');
        $this->addSql('ALTER TABLE ente_richiesta DROP CONSTRAINT FK_9383B1ED20958DFB');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E6C8028080');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E6AE71F7DF');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E630D47B88');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E663C592CC');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E6C4F50528');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E699C34053');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario_referente DROP CONSTRAINT FK_C8737DC3F575179');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario_referente DROP CONSTRAINT FK_C8737DCC485953B');
        $this->addSql('ALTER TABLE ente_sede DROP CONSTRAINT FK_C41E6DCA9033212A');
        $this->addSql('DROP TABLE ente_anno');
        $this->addSql('DROP TABLE ente_collegamento');
        $this->addSql('DROP TABLE ente_file');
        $this->addSql('DROP TABLE ente_gruppo');
        $this->addSql('DROP TABLE ente_gruppo_operatore');
        $this->addSql('DROP TABLE ente_iscritto');
        $this->addSql('DROP TABLE ente_iscrizione');
        $this->addSql('DROP TABLE ente_profilo');
        $this->addSql('DROP TABLE ente_referente');
        $this->addSql('DROP TABLE ente_richiesta');
        $this->addSql('DROP TABLE ente_richiesta_destinatario');
        $this->addSql('DROP TABLE ente_richiesta_destinatario_referente');
        $this->addSql('DROP TABLE ente_sede');
        $this->addSql('ALTER TABLE scadenza DROP CONSTRAINT FK_F36D33B9C8028080');
        $this->addSql('DROP INDEX IDX_F36D33B9C8028080');
        $this->addSql('ALTER TABLE scadenza DROP richiesta_id');
    }
}
