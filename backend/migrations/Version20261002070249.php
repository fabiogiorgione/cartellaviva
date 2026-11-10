<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002070249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ente_modello (id UUID NOT NULL, nome VARCHAR(120) NOT NULL, tipo VARCHAR(20) NOT NULL, titolo VARCHAR(200) NOT NULL, istruzioni TEXT DEFAULT NULL, firmatari VARCHAR(10) NOT NULL, chiedi_validita BOOLEAN DEFAULT false NOT NULL, rinnovo_giorni INT DEFAULT NULL, conservazione_mesi INT DEFAULT NULL, ricorrenza_giorno INT DEFAULT NULL, ricorrenza_mese INT DEFAULT NULL, prossimo_invio DATE DEFAULT NULL, giorni_risposta INT DEFAULT 14 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tenant_id UUID NOT NULL, allegato_id UUID DEFAULT NULL, creato_da_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8AD74C1A9033212A ON ente_modello (tenant_id)');
        $this->addSql('CREATE INDEX IDX_8AD74C1A68F4D369 ON ente_modello (allegato_id)');
        $this->addSql('CREATE INDEX IDX_8AD74C1A20958DFB ON ente_modello (creato_da_id)');
        $this->addSql('ALTER TABLE ente_modello ADD CONSTRAINT FK_8AD74C1A9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_modello ADD CONSTRAINT FK_8AD74C1A68F4D369 FOREIGN KEY (allegato_id) REFERENCES ente_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_modello ADD CONSTRAINT FK_8AD74C1A20958DFB FOREIGN KEY (creato_da_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta ADD chiedi_validita BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE ente_richiesta ADD rinnovo_giorni INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta ADD conservazione_mesi INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta ADD modello_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta ADD rinnovo_di_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta ADD CONSTRAINT FK_9383B1ED10D7CADA FOREIGN KEY (modello_id) REFERENCES ente_modello (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ente_richiesta ADD CONSTRAINT FK_9383B1ED77F47543 FOREIGN KEY (rinnovo_di_id) REFERENCES ente_richiesta_destinatario (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9383B1ED10D7CADA ON ente_richiesta (modello_id)');
        $this->addSql('CREATE INDEX IDX_9383B1ED77F47543 ON ente_richiesta (rinnovo_di_id)');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD link_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD link_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD otp_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD otp_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD otp_tentativi INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD sessione_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD sessione_scadenza TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD valido_fino DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD rinnovata_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD file_rimosso_il TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD referente_link_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ALTER famiglia_id DROP NOT NULL');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E66FA63D48 FOREIGN KEY (referente_link_id) REFERENCES ente_referente (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3F0319E6E436972F ON ente_richiesta_destinatario (link_token_hash)');
        $this->addSql('CREATE INDEX IDX_3F0319E66FA63D48 ON ente_richiesta_destinatario (referente_link_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ente_modello DROP CONSTRAINT FK_8AD74C1A9033212A');
        $this->addSql('ALTER TABLE ente_modello DROP CONSTRAINT FK_8AD74C1A68F4D369');
        $this->addSql('ALTER TABLE ente_modello DROP CONSTRAINT FK_8AD74C1A20958DFB');
        $this->addSql('DROP TABLE ente_modello');
        $this->addSql('ALTER TABLE ente_richiesta DROP CONSTRAINT FK_9383B1ED10D7CADA');
        $this->addSql('ALTER TABLE ente_richiesta DROP CONSTRAINT FK_9383B1ED77F47543');
        $this->addSql('DROP INDEX IDX_9383B1ED10D7CADA');
        $this->addSql('DROP INDEX IDX_9383B1ED77F47543');
        $this->addSql('ALTER TABLE ente_richiesta DROP chiedi_validita');
        $this->addSql('ALTER TABLE ente_richiesta DROP rinnovo_giorni');
        $this->addSql('ALTER TABLE ente_richiesta DROP conservazione_mesi');
        $this->addSql('ALTER TABLE ente_richiesta DROP modello_id');
        $this->addSql('ALTER TABLE ente_richiesta DROP rinnovo_di_id');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E66FA63D48');
        $this->addSql('DROP INDEX UNIQ_3F0319E6E436972F');
        $this->addSql('DROP INDEX IDX_3F0319E66FA63D48');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP link_token_hash');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP link_scadenza');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP otp_hash');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP otp_scadenza');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP otp_tentativi');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP sessione_hash');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP sessione_scadenza');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP valido_fino');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP rinnovata_il');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP file_rimosso_il');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP referente_link_id');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ALTER famiglia_id SET NOT NULL');
    }
}
