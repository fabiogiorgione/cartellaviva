<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002064029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE procedura_accesso (id UUID NOT NULL, tipo VARCHAR(30) NOT NULL, stato VARCHAR(30) NOT NULL, motivo VARCHAR(500) DEFAULT NULL, token_conferma_hash VARCHAR(64) DEFAULT NULL, token_conferma_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, token_annulla_hash VARCHAR(64) DEFAULT NULL, verifica_documento VARCHAR(120) DEFAULT NULL, verifica_come VARCHAR(120) DEFAULT NULL, nota VARCHAR(500) DEFAULT NULL, eseguibile_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, chiusa_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, tenant_id UUID DEFAULT NULL, verificata_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_procedura_stato ON procedura_accesso (stato, eseguibile_il)');
        $this->addSql('CREATE UNIQUE INDEX uniq_procedura_conferma ON procedura_accesso (token_conferma_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_procedura_annulla ON procedura_accesso (token_annulla_hash)');
        $this->addSql('CREATE INDEX IDX_6FABD467A76ED395 ON procedura_accesso (user_id)');
        $this->addSql('CREATE INDEX IDX_6FABD4679033212A ON procedura_accesso (tenant_id)');
        $this->addSql('CREATE INDEX IDX_6FABD4678087B98F ON procedura_accesso (verificata_da_id)');
        $this->addSql('ALTER TABLE procedura_accesso ADD CONSTRAINT FK_6FABD467A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE procedura_accesso ADD CONSTRAINT FK_6FABD4679033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE procedura_accesso ADD CONSTRAINT FK_6FABD4678087B98F FOREIGN KEY (verificata_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_user ADD platform_role VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD two_factor_obbligatoria BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE procedura_accesso DROP CONSTRAINT FK_6FABD467A76ED395');
        $this->addSql('ALTER TABLE procedura_accesso DROP CONSTRAINT FK_6FABD4679033212A');
        $this->addSql('ALTER TABLE procedura_accesso DROP CONSTRAINT FK_6FABD4678087B98F');
        $this->addSql('DROP TABLE procedura_accesso');
        $this->addSql('ALTER TABLE app_user DROP platform_role');
        $this->addSql('ALTER TABLE app_user DROP two_factor_obbligatoria');
    }
}
