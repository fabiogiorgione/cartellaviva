<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connettore AI sul Mac: anche i dati dell'indice dei documenti (docs/connettore-ai.md).
 */
final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connettore AI: lavori d\'indice dei documenti (senza pratica, uno per documento)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lavoro_ai ALTER pratica_id DROP NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX uniq_lavoro_ai_indice ON lavoro_ai (documento_id) WHERE (tipo = 'indice_dati')");
    }

    public function down(Schema $schema): void
    {
        // I lavori d'indice non hanno pratica: si tolgono (il server rifà l'indice con app:documenti:indicizza).
        $this->addSql("DELETE FROM lavoro_ai WHERE tipo = 'indice_dati'");
        $this->addSql('DROP INDEX uniq_lavoro_ai_indice');
        $this->addSql('ALTER TABLE lavoro_ai ALTER pratica_id SET NOT NULL');
    }
}
