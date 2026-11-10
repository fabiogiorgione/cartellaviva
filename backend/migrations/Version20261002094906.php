<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002094906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ente_firma_codice (id UUID NOT NULL, nome_firmatario VARCHAR(200) NOT NULL, email_firmatario VARCHAR(180) NOT NULL, documento_sha256 VARCHAR(64) NOT NULL, otp_hash VARCHAR(64) DEFAULT NULL, otp_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, otp_tentativi INT NOT NULL, firmata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, dichiarazione VARCHAR(255) NOT NULL, codice_verifica VARCHAR(16) DEFAULT NULL, documento_firmato_sha256 VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, destinatario_id UUID NOT NULL, referente_id UUID NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_firma_codice_dest ON ente_firma_codice (destinatario_id, referente_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_firma_codice_verifica ON ente_firma_codice (codice_verifica)');
        $this->addSql('CREATE INDEX IDX_92314684B564FBC1 ON ente_firma_codice (destinatario_id)');
        $this->addSql('CREATE INDEX IDX_92314684C485953B ON ente_firma_codice (referente_id)');
        $this->addSql('CREATE INDEX IDX_92314684A76ED395 ON ente_firma_codice (user_id)');
        $this->addSql('ALTER TABLE ente_firma_codice ADD CONSTRAINT FK_92314684B564FBC1 FOREIGN KEY (destinatario_id) REFERENCES ente_richiesta_destinatario (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_firma_codice ADD CONSTRAINT FK_92314684C485953B FOREIGN KEY (referente_id) REFERENCES ente_referente (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_firma_codice ADD CONSTRAINT FK_92314684A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_modello ADD modalita_firma VARCHAR(10) DEFAULT \'cartacea\' NOT NULL');
        $this->addSql('ALTER TABLE ente_richiesta ADD modalita_firma VARCHAR(10) DEFAULT \'cartacea\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ente_firma_codice DROP CONSTRAINT FK_92314684B564FBC1');
        $this->addSql('ALTER TABLE ente_firma_codice DROP CONSTRAINT FK_92314684C485953B');
        $this->addSql('ALTER TABLE ente_firma_codice DROP CONSTRAINT FK_92314684A76ED395');
        $this->addSql('DROP TABLE ente_firma_codice');
        $this->addSql('ALTER TABLE ente_modello DROP modalita_firma');
        $this->addSql('ALTER TABLE ente_richiesta DROP modalita_firma');
    }
}
