<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002141834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contatto_emergenza (id UUID NOT NULL, email VARCHAR(180) NOT NULL, categorie JSON DEFAULT NULL, giorni_attesa INT NOT NULL, stato VARCHAR(10) NOT NULL, invito_token_hash VARCHAR(64) DEFAULT NULL, invito_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, richiesta_stato VARCHAR(10) DEFAULT NULL, richiesta_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, eseguibile_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, motivo VARCHAR(500) DEFAULT NULL, annulla_token_hash VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, configurato_da_id UUID DEFAULT NULL, contatto_id UUID DEFAULT NULL, negata_da_id UUID DEFAULT NULL, membership_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_emergenza_richiesta ON contatto_emergenza (richiesta_stato, eseguibile_il)');
        $this->addSql('CREATE UNIQUE INDEX uniq_emergenza_invito ON contatto_emergenza (invito_token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_emergenza_annulla ON contatto_emergenza (annulla_token_hash)');
        $this->addSql('CREATE INDEX IDX_C58A33479033212A ON contatto_emergenza (tenant_id)');
        $this->addSql('CREATE INDEX IDX_C58A3347B800A1CC ON contatto_emergenza (configurato_da_id)');
        $this->addSql('CREATE INDEX IDX_C58A334775836E8A ON contatto_emergenza (contatto_id)');
        $this->addSql('CREATE INDEX IDX_C58A33476D3DF63D ON contatto_emergenza (negata_da_id)');
        $this->addSql('CREATE INDEX IDX_C58A33471FB354CD ON contatto_emergenza (membership_id)');
        $this->addSql('ALTER TABLE contatto_emergenza ADD CONSTRAINT FK_C58A33479033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contatto_emergenza ADD CONSTRAINT FK_C58A3347B800A1CC FOREIGN KEY (configurato_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contatto_emergenza ADD CONSTRAINT FK_C58A334775836E8A FOREIGN KEY (contatto_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contatto_emergenza ADD CONSTRAINT FK_C58A33476D3DF63D FOREIGN KEY (negata_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contatto_emergenza ADD CONSTRAINT FK_C58A33471FB354CD FOREIGN KEY (membership_id) REFERENCES membership (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membership ADD categorie_consentite JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contatto_emergenza DROP CONSTRAINT FK_C58A33479033212A');
        $this->addSql('ALTER TABLE contatto_emergenza DROP CONSTRAINT FK_C58A3347B800A1CC');
        $this->addSql('ALTER TABLE contatto_emergenza DROP CONSTRAINT FK_C58A334775836E8A');
        $this->addSql('ALTER TABLE contatto_emergenza DROP CONSTRAINT FK_C58A33476D3DF63D');
        $this->addSql('ALTER TABLE contatto_emergenza DROP CONSTRAINT FK_C58A33471FB354CD');
        $this->addSql('DROP TABLE contatto_emergenza');
        $this->addSql('ALTER TABLE membership DROP categorie_consentite');
    }
}
