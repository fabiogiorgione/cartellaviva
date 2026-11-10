<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data del documento per quelli già indicizzati: quella trovata dall'AI, solo
 * dove manca. Da ora in poi la imposta IndicizzaDocumentoHandler, con la stessa regola.
 */
final class Version20261109100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documenti indicizzati: data del documento da quella trovata nel testo, dove manca';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE documento d
            SET data_documento = CAST(i.dati->>'data' AS DATE)
            FROM documento_indice i
            WHERE i.documento_id = d.id
              AND d.data_documento IS NULL
              AND i.dati->>'data' ~ '^\d{4}-\d{2}-\d{2}$'
              AND i.dati->>'data' BETWEEN '1900-01-01' AND TO_CHAR(CURRENT_DATE, 'YYYY-MM-DD')
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Non reversibile: dopo non si distinguerebbero dalle date inserite a mano.
    }
}
