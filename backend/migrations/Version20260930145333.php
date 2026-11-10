<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930145333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import da sorgenti esterne (Google Drive): import_source con token cifrati, provenienza e HMAC dell\'MD5 sui documenti';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE import_source (id UUID NOT NULL, provider VARCHAR(30) NOT NULL, oauth_access_token TEXT DEFAULT NULL, oauth_refresh_token TEXT DEFAULT NULL, oauth_token_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, encrypted_dek TEXT DEFAULT NULL, key_version INT DEFAULT NULL, account_esterno VARCHAR(255) DEFAULT NULL, oauth_state_hash VARCHAR(64) DEFAULT NULL, oauth_state_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cartella_radice_id VARCHAR(255) NOT NULL, strategia VARCHAR(20) NOT NULL, stato VARCHAR(20) NOT NULL, ultima_sincronizzazione TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sincronizzazione_richiesta_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ultimo_errore VARCHAR(500) DEFAULT NULL, ultimo_esito JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tenant_id UUID NOT NULL, creato_da_user_id UUID NOT NULL, oauth_state_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_import_source_tenant ON import_source (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_import_source_oauth_state ON import_source (oauth_state_hash)');
        $this->addSql('CREATE INDEX IDX_23BBC56B3FE0EE9F ON import_source (creato_da_user_id)');
        $this->addSql('CREATE INDEX IDX_23BBC56B739D7FF3 ON import_source (oauth_state_user_id)');
        $this->addSql('ALTER TABLE import_source ADD CONSTRAINT FK_23BBC56B9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_source ADD CONSTRAINT FK_23BBC56B3FE0EE9F FOREIGN KEY (creato_da_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_source ADD CONSTRAINT FK_23BBC56B739D7FF3 FOREIGN KEY (oauth_state_user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documento ADD hash_md5 VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE documento ADD sorgente_file_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE documento ADD sorgente_versione VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE documento ADD import_source_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE documento ADD CONSTRAINT FK_B6B12EC7C8FDA8DD FOREIGN KEY (import_source_id) REFERENCES import_source (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_documento_tenant_md5 ON documento (tenant_id, hash_md5)');
        $this->addSql('CREATE INDEX idx_documento_sorgente_file ON documento (import_source_id, sorgente_file_id)');
        $this->addSql('CREATE INDEX IDX_B6B12EC7C8FDA8DD ON documento (import_source_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documento DROP CONSTRAINT FK_B6B12EC7C8FDA8DD');
        $this->addSql('DROP TABLE import_source');
        $this->addSql('DROP INDEX idx_documento_tenant_md5');
        $this->addSql('DROP INDEX idx_documento_sorgente_file');
        $this->addSql('DROP INDEX IDX_B6B12EC7C8FDA8DD');
        $this->addSql('ALTER TABLE documento DROP hash_md5');
        $this->addSql('ALTER TABLE documento DROP sorgente_file_id');
        $this->addSql('ALTER TABLE documento DROP sorgente_versione');
        $this->addSql('ALTER TABLE documento DROP import_source_id');
    }
}
