<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009123031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le risposte all\'ente restano dopo la cancellazione della famiglia (famiglia_id ON DELETE SET NULL)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT fk_3f0319e630d47b88');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT FK_3F0319E630D47B88 FOREIGN KEY (famiglia_id) REFERENCES tenant (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ente_richiesta_destinatario DROP CONSTRAINT FK_3F0319E630D47B88');
        $this->addSql('ALTER TABLE ente_richiesta_destinatario ADD CONSTRAINT fk_3f0319e630d47b88 FOREIGN KEY (famiglia_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
