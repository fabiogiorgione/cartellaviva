<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pratiche, fase 1: pagamenti (avvisi PagoPA, bollettini) e la proposta di
 * pagamento sul documento, come la scadenza proposta.
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pagamenti: tabella pagamento e proposta di pagamento sui documenti';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE pagamento (
                id UUID NOT NULL,
                titolo VARCHAR(200) NOT NULL,
                ente VARCHAR(200) DEFAULT NULL,
                importo_centesimi INT NOT NULL,
                scadenza DATE DEFAULT NULL,
                codice_avviso VARCHAR(18) DEFAULT NULL,
                codice_fiscale_ente VARCHAR(11) DEFAULT NULL,
                stato VARCHAR(20) NOT NULL,
                pagato_il DATE DEFAULT NULL,
                origine VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                tenant_id UUID NOT NULL,
                owner_user_id UUID NOT NULL,
                documento_id UUID DEFAULT NULL,
                ricevuta_id UUID DEFAULT NULL,
                scadenza_collegata_id UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_pagamento_tenant_stato ON pagamento (tenant_id, stato)');
        $this->addSql('CREATE INDEX IDX_3E1F4B162B18554A ON pagamento (owner_user_id)');
        $this->addSql('CREATE INDEX IDX_3E1F4B1645C0CF75 ON pagamento (documento_id)');
        $this->addSql('CREATE INDEX IDX_3E1F4B16DF41D5F2 ON pagamento (ricevuta_id)');
        $this->addSql('CREATE INDEX IDX_3E1F4B16EC92EE0F ON pagamento (scadenza_collegata_id)');
        $this->addSql('ALTER TABLE pagamento ADD CONSTRAINT FK_PAGAMENTO_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pagamento ADD CONSTRAINT FK_PAGAMENTO_OWNER FOREIGN KEY (owner_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pagamento ADD CONSTRAINT FK_PAGAMENTO_DOCUMENTO FOREIGN KEY (documento_id) REFERENCES documento (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pagamento ADD CONSTRAINT FK_PAGAMENTO_RICEVUTA FOREIGN KEY (ricevuta_id) REFERENCES documento (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pagamento ADD CONSTRAINT FK_PAGAMENTO_SCADENZA FOREIGN KEY (scadenza_collegata_id) REFERENCES scadenza (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documento ADD pagamento_proposta TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE documento ADD pagamento_proposta_stato VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documento DROP pagamento_proposta_stato');
        $this->addSql('ALTER TABLE documento DROP pagamento_proposta');
        $this->addSql('DROP TABLE pagamento');
    }
}
