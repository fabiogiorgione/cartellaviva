<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indice dei documenti NON cifrati: dati estratti, tag, riferimenti e vettori
 * (embedding) per la ricerca per significato e i documenti simili.
 *
 * Tabelle gestite con SQL diretto (App\Documenti\Indice\IndiceDocumenti), fuori
 * dall'ORM: Doctrine non conosce il tipo "vector" (vedi schema_filter in doctrine.yaml).
 * Nessun indice HNSW: si cerca sempre dentro una sola famiglia (poche migliaia di
 * documenti al massimo), dove la ricerca esatta è veloce e non perde risultati.
 */
final class Version20261107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ricerca per significato: pgvector, documento_indice e documento_embedding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql(<<<'SQL'
            CREATE TABLE documento_indice (
                documento_id UUID NOT NULL PRIMARY KEY REFERENCES documento (id) ON DELETE CASCADE,
                tenant_id UUID NOT NULL REFERENCES tenant (id) ON DELETE CASCADE,
                dati JSONB NOT NULL DEFAULT '{}',
                tag JSONB NOT NULL DEFAULT '[]',
                riferimenti JSONB NOT NULL DEFAULT '[]',
                modello_dati VARCHAR(100) DEFAULT NULL,
                modello_embedding VARCHAR(100) DEFAULT NULL,
                aggiornato_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_documento_indice_tenant ON documento_indice (tenant_id)');
        // Riferimenti (targa, POD, numero polizza...): "altri documenti della stessa auto".
        $this->addSql('CREATE INDEX idx_documento_indice_riferimenti ON documento_indice USING GIN (riferimenti)');
        // Più parti per documento: un testo lungo in un solo vettore perderebbe i dettagli.
        // "vector" senza dimensione: la dimensione dipende dal modello (bge-m3 = 1024).
        $this->addSql(<<<'SQL'
            CREATE TABLE documento_embedding (
                documento_id UUID NOT NULL REFERENCES documento (id) ON DELETE CASCADE,
                parte SMALLINT NOT NULL,
                tenant_id UUID NOT NULL REFERENCES tenant (id) ON DELETE CASCADE,
                modello VARCHAR(100) NOT NULL,
                embedding vector NOT NULL,
                PRIMARY KEY (documento_id, parte)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_documento_embedding_tenant ON documento_embedding (tenant_id, modello)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE documento_embedding');
        $this->addSql('DROP TABLE documento_indice');
        $this->addSql('DROP EXTENSION IF EXISTS vector');
    }
}
