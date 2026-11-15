<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connettore AI sul Mac (docs/connettore-ai.md).
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connettore AI sul Mac: connettori, richieste di abbinamento, coda dei lavori; registro del modello nelle analisi; consenso dei cifrati per i dispositivi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE connettore_ai (id UUID NOT NULL, nome VARCHAR(80) NOT NULL, approvato_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revocato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, access_token_hash VARCHAR(64) NOT NULL, access_scade_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refresh_token_hash VARCHAR(64) NOT NULL, refresh_scade_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refresh_precedente_hash VARCHAR(64) DEFAULT NULL, ultimo_contatto TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, versione VARCHAR(20) DEFAULT NULL, sistema VARCHAR(40) DEFAULT NULL, chip VARCHAR(40) DEFAULT NULL, memoria_gb INT DEFAULT NULL, motore VARCHAR(10) DEFAULT NULL, modello VARCHAR(100) DEFAULT NULL, modello_digest VARCHAR(80) DEFAULT NULL, contesto INT DEFAULT NULL, lavori_fatti INT DEFAULT 0 NOT NULL, durata_media_ms INT DEFAULT NULL, tenant_id UUID NOT NULL, approvato_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_connettore_ai_tenant ON connettore_ai (tenant_id)');
        $this->addSql('CREATE INDEX idx_connettore_ai_refresh_precedente ON connettore_ai (refresh_precedente_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_connettore_ai_access ON connettore_ai (access_token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_connettore_ai_refresh ON connettore_ai (refresh_token_hash)');
        $this->addSql('CREATE INDEX IDX_54EEC6A9198B0B0D ON connettore_ai (approvato_da_id)');
        $this->addSql('CREATE TABLE connettore_ai_richiesta (id UUID NOT NULL, device_code_hash VARCHAR(64) NOT NULL, user_code VARCHAR(9) NOT NULL, nome_proposto VARCHAR(80) NOT NULL, stato VARCHAR(10) NOT NULL, scade_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ultimo_poll_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tenant_id UUID DEFAULT NULL, approvata_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_connettore_ai_richiesta_codice ON connettore_ai_richiesta (user_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_connettore_ai_richiesta_device ON connettore_ai_richiesta (device_code_hash)');
        $this->addSql('CREATE INDEX IDX_8446B6299033212A ON connettore_ai_richiesta (tenant_id)');
        $this->addSql('CREATE INDEX IDX_8446B62949B47050 ON connettore_ai_richiesta (approvata_da_id)');
        $this->addSql('CREATE TABLE lavoro_ai (id UUID NOT NULL, tipo VARCHAR(20) NOT NULL, stato VARCHAR(10) NOT NULL, assegnato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, scade_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tentativi SMALLINT DEFAULT 0 NOT NULL, creato_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, pratica_id UUID NOT NULL, documento_id UUID NOT NULL, connettore_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_lavoro_ai_tenant_stato ON lavoro_ai (tenant_id, stato)');
        $this->addSql('CREATE UNIQUE INDEX uniq_lavoro_ai ON lavoro_ai (tipo, pratica_id, documento_id)');
        $this->addSql('CREATE INDEX IDX_F93A20C89033212A ON lavoro_ai (tenant_id)');
        $this->addSql('CREATE INDEX IDX_F93A20C824038DEB ON lavoro_ai (pratica_id)');
        $this->addSql('CREATE INDEX IDX_F93A20C845C0CF75 ON lavoro_ai (documento_id)');
        $this->addSql('CREATE INDEX IDX_F93A20C8E286301C ON lavoro_ai (connettore_id)');
        $this->addSql('ALTER TABLE connettore_ai ADD CONSTRAINT FK_54EEC6A99033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE connettore_ai ADD CONSTRAINT FK_54EEC6A9198B0B0D FOREIGN KEY (approvato_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE connettore_ai_richiesta ADD CONSTRAINT FK_8446B6299033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE connettore_ai_richiesta ADD CONSTRAINT FK_8446B62949B47050 FOREIGN KEY (approvata_da_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE lavoro_ai ADD CONSTRAINT FK_F93A20C89033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE lavoro_ai ADD CONSTRAINT FK_F93A20C824038DEB FOREIGN KEY (pratica_id) REFERENCES pratica (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE lavoro_ai ADD CONSTRAINT FK_F93A20C845C0CF75 FOREIGN KEY (documento_id) REFERENCES documento (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE lavoro_ai ADD CONSTRAINT FK_F93A20C8E286301C FOREIGN KEY (connettore_id) REFERENCES connettore_ai (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pratica ADD consenso_cifrati_dispositivi_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pratica_analisi ADD modello VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE pratica_analisi ADD durata_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pratica_analisi ADD connettore_ai_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE pratica_analisi ADD CONSTRAINT FK_451748EB6AFA1C3C FOREIGN KEY (connettore_ai_id) REFERENCES connettore_ai (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_451748EB6AFA1C3C ON pratica_analisi (connettore_ai_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connettore_ai DROP CONSTRAINT FK_54EEC6A99033212A');
        $this->addSql('ALTER TABLE connettore_ai DROP CONSTRAINT FK_54EEC6A9198B0B0D');
        $this->addSql('ALTER TABLE connettore_ai_richiesta DROP CONSTRAINT FK_8446B6299033212A');
        $this->addSql('ALTER TABLE connettore_ai_richiesta DROP CONSTRAINT FK_8446B62949B47050');
        $this->addSql('ALTER TABLE lavoro_ai DROP CONSTRAINT FK_F93A20C89033212A');
        $this->addSql('ALTER TABLE lavoro_ai DROP CONSTRAINT FK_F93A20C824038DEB');
        $this->addSql('ALTER TABLE lavoro_ai DROP CONSTRAINT FK_F93A20C845C0CF75');
        $this->addSql('ALTER TABLE lavoro_ai DROP CONSTRAINT FK_F93A20C8E286301C');
        $this->addSql('DROP TABLE connettore_ai');
        $this->addSql('DROP TABLE connettore_ai_richiesta');
        $this->addSql('DROP TABLE lavoro_ai');
        $this->addSql('ALTER TABLE pratica DROP consenso_cifrati_dispositivi_il');
        $this->addSql('ALTER TABLE pratica_analisi DROP CONSTRAINT FK_451748EB6AFA1C3C');
        $this->addSql('DROP INDEX IDX_451748EB6AFA1C3C');
        $this->addSql('ALTER TABLE pratica_analisi DROP modello');
        $this->addSql('ALTER TABLE pratica_analisi DROP durata_ms');
        $this->addSql('ALTER TABLE pratica_analisi DROP connettore_ai_id');
    }
}
