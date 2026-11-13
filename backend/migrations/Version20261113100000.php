<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pratiche: dove cercare (categorie, etichette, analizza tutti).
 */
final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pratiche: colonna ambito (categorie ed etichette in cui cercare)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pratica ADD ambito JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pratica DROP ambito');
    }
}
