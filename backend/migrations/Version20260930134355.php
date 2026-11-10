<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modello documentale: membri della famiglia, categorie, documenti, coda Messenger,
 * impronte delle versioni della master key.
 */
final class Version20260930134355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documenti, categorie, membri della famiglia, coda OCR, impronte master key (immutabili)';
    }

    public function postUp(Schema $schema): void
    {
        // Le impronte registrate non si modificano né si cancellano: servono a
        // rifiutare un file di master key sbagliato.
        $this->connection->executeStatement(<<<'SQL'
            CREATE OR REPLACE FUNCTION crypto_key_version_forbid_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'crypto_key_version è immutabile (% non consentito)', TG_OP;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TRIGGER crypto_key_version_immutable
                BEFORE UPDATE OR DELETE ON crypto_key_version
                FOR EACH ROW EXECUTE FUNCTION crypto_key_version_forbid_change()
            SQL);
    }

    public function preDown(Schema $schema): void
    {
        $this->connection->executeStatement('DROP TRIGGER IF EXISTS crypto_key_version_immutable ON crypto_key_version');
        $this->connection->executeStatement('DROP FUNCTION IF EXISTS crypto_key_version_forbid_change()');
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE categoria (id UUID NOT NULL, slug VARCHAR(50) DEFAULT NULL, nome VARCHAR(80) NOT NULL, icona VARCHAR(50) NOT NULL, colore VARCHAR(7) NOT NULL, is_sensibile BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tenant_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_categoria_system_slug ON categoria (slug) WHERE (tenant_id IS NULL)');
        $this->addSql('CREATE INDEX IDX_4E10122D9033212A ON categoria (tenant_id)');
        $this->addSql('CREATE TABLE crypto_key_version (version INT NOT NULL, fingerprint VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (version))');
        $this->addSql('CREATE TABLE documento (id UUID NOT NULL, titolo VARCHAR(200) NOT NULL, nome_file_originale VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, dimensione_bytes BIGINT NOT NULL, hash_sha256 VARCHAR(64) NOT NULL, hash_tipo VARCHAR(20) NOT NULL, cifrato BOOLEAN NOT NULL, encrypted_dek TEXT DEFAULT NULL, key_version INT DEFAULT NULL, ocr_text TEXT DEFAULT NULL, ocr_text_cifrato TEXT DEFAULT NULL, ocr_status VARCHAR(20) NOT NULL, ocr_errore VARCHAR(500) DEFAULT NULL, data_documento DATE DEFAULT NULL, data_conservazione_fino DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, categoria_id UUID NOT NULL, tenant_id UUID NOT NULL, owner_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B6B12EC7111795A5 ON documento (storage_key)');
        $this->addSql('CREATE INDEX idx_documento_tenant_created ON documento (tenant_id, created_at)');
        $this->addSql('CREATE INDEX idx_documento_tenant_hash ON documento (tenant_id, hash_sha256)');
        $this->addSql('CREATE INDEX IDX_B6B12EC73397707A ON documento (categoria_id)');
        $this->addSql('CREATE INDEX IDX_B6B12EC79033212A ON documento (tenant_id)');
        $this->addSql('CREATE INDEX IDX_B6B12EC72B18554A ON documento (owner_user_id)');
        $this->addSql('CREATE TABLE documento_membro_famiglia (documento_id UUID NOT NULL, membro_famiglia_id UUID NOT NULL, PRIMARY KEY (documento_id, membro_famiglia_id))');
        $this->addSql('CREATE INDEX IDX_9092432145C0CF75 ON documento_membro_famiglia (documento_id)');
        $this->addSql('CREATE INDEX IDX_9092432119BD3F42 ON documento_membro_famiglia (membro_famiglia_id)');
        $this->addSql('CREATE TABLE membro_famiglia (id UUID NOT NULL, relazione VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, anagrafica_id UUID NOT NULL, user_id UUID DEFAULT NULL, tenant_id UUID NOT NULL, owner_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B5D93C987E92415C ON membro_famiglia (anagrafica_id)');
        $this->addSql('CREATE INDEX IDX_B5D93C98A76ED395 ON membro_famiglia (user_id)');
        $this->addSql('CREATE INDEX IDX_B5D93C989033212A ON membro_famiglia (tenant_id)');
        $this->addSql('CREATE INDEX IDX_B5D93C982B18554A ON membro_famiglia (owner_user_id)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('ALTER TABLE categoria ADD CONSTRAINT FK_4E10122D9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documento ADD CONSTRAINT FK_B6B12EC73397707A FOREIGN KEY (categoria_id) REFERENCES categoria (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documento ADD CONSTRAINT FK_B6B12EC79033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documento ADD CONSTRAINT FK_B6B12EC72B18554A FOREIGN KEY (owner_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documento_membro_famiglia ADD CONSTRAINT FK_9092432145C0CF75 FOREIGN KEY (documento_id) REFERENCES documento (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documento_membro_famiglia ADD CONSTRAINT FK_9092432119BD3F42 FOREIGN KEY (membro_famiglia_id) REFERENCES membro_famiglia (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membro_famiglia ADD CONSTRAINT FK_B5D93C987E92415C FOREIGN KEY (anagrafica_id) REFERENCES anagrafica (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membro_famiglia ADD CONSTRAINT FK_B5D93C98A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membro_famiglia ADD CONSTRAINT FK_B5D93C989033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membro_famiglia ADD CONSTRAINT FK_B5D93C982B18554A FOREIGN KEY (owner_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categoria DROP CONSTRAINT FK_4E10122D9033212A');
        $this->addSql('ALTER TABLE documento DROP CONSTRAINT FK_B6B12EC73397707A');
        $this->addSql('ALTER TABLE documento DROP CONSTRAINT FK_B6B12EC79033212A');
        $this->addSql('ALTER TABLE documento DROP CONSTRAINT FK_B6B12EC72B18554A');
        $this->addSql('ALTER TABLE documento_membro_famiglia DROP CONSTRAINT FK_9092432145C0CF75');
        $this->addSql('ALTER TABLE documento_membro_famiglia DROP CONSTRAINT FK_9092432119BD3F42');
        $this->addSql('ALTER TABLE membro_famiglia DROP CONSTRAINT FK_B5D93C987E92415C');
        $this->addSql('ALTER TABLE membro_famiglia DROP CONSTRAINT FK_B5D93C98A76ED395');
        $this->addSql('ALTER TABLE membro_famiglia DROP CONSTRAINT FK_B5D93C989033212A');
        $this->addSql('ALTER TABLE membro_famiglia DROP CONSTRAINT FK_B5D93C982B18554A');
        $this->addSql('DROP TABLE categoria');
        $this->addSql('DROP TABLE crypto_key_version');
        $this->addSql('DROP TABLE documento');
        $this->addSql('DROP TABLE documento_membro_famiglia');
        $this->addSql('DROP TABLE membro_famiglia');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
