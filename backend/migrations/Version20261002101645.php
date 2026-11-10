<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002101645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE idea (id UUID NOT NULL, tipo VARCHAR(20) NOT NULL, area VARCHAR(20) DEFAULT NULL, testo TEXT NOT NULL, contattabile BOOLEAN NOT NULL, tipo_spazio VARCHAR(20) DEFAULT NULL, piattaforma VARCHAR(20) DEFAULT NULL, versione_app VARCHAR(20) DEFAULT NULL, stato VARCHAR(20) NOT NULL, risposta_pubblica TEXT DEFAULT NULL, nota_interna TEXT DEFAULT NULL, riepilogo_inviato_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, aggiornata_il TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, autore_id UUID DEFAULT NULL, duplicato_di_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_idea_stato ON idea (stato, created_at)');
        $this->addSql('CREATE INDEX idx_idea_riepilogo ON idea (riepilogo_inviato_il)');
        $this->addSql('CREATE INDEX IDX_A8BCA45BECEFFB6 ON idea (autore_id)');
        $this->addSql('CREATE INDEX IDX_A8BCA45A7004D09 ON idea (duplicato_di_id)');
        $this->addSql('ALTER TABLE idea ADD CONSTRAINT FK_A8BCA45BECEFFB6 FOREIGN KEY (autore_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE idea ADD CONSTRAINT FK_A8BCA45A7004D09 FOREIGN KEY (duplicato_di_id) REFERENCES idea (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE idea DROP CONSTRAINT FK_A8BCA45BECEFFB6');
        $this->addSql('ALTER TABLE idea DROP CONSTRAINT FK_A8BCA45A7004D09');
        $this->addSql('DROP TABLE idea');
    }
}
