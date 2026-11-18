<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connettore AI sul Mac: anche classificazione e proposta di categoria (docs/connettore-ai.md).
 */
final class Version20261118180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connettore AI: lavori di classificazione e di proposta di categoria (stato della catena in "dati")';
    }

    public function up(Schema $schema): void
    {
        // Un lavoro senza pratica per tipo e documento (prima valeva solo per indice_dati).
        $this->addSql('DROP INDEX uniq_lavoro_ai_indice');
        $this->addSql('CREATE UNIQUE INDEX uniq_lavoro_ai_documento ON lavoro_ai (tipo, documento_id) WHERE (pratica_id IS NULL)');
        $this->addSql('ALTER TABLE lavoro_ai ADD dati JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Le classificazioni in corso si perdono: il server le rifà (import) o il wizard le richiede.
        $this->addSql("DELETE FROM lavoro_ai WHERE tipo IN ('classificazione', 'proposta_categoria')");
        $this->addSql('ALTER TABLE lavoro_ai DROP dati');
        $this->addSql('DROP INDEX uniq_lavoro_ai_documento');
        $this->addSql("CREATE UNIQUE INDEX uniq_lavoro_ai_indice ON lavoro_ai (documento_id) WHERE (tipo = 'indice_dati')");
    }
}
