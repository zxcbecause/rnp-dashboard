<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Screenshot attached to a call exclusion request, kept in the database (travels with the nightly dump).
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'call_exclusion_attachment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE call_exclusion_attachment (id INT AUTO_INCREMENT NOT NULL, content_type VARCHAR(32) NOT NULL, size INT NOT NULL, content MEDIUMBLOB NOT NULL, created_at DATETIME NOT NULL, exclusion_id INT NOT NULL, UNIQUE INDEX UNIQ_4A501F064012D45C (exclusion_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE call_exclusion_attachment ADD CONSTRAINT FK_4A501F064012D45C FOREIGN KEY (exclusion_id) REFERENCES call_exclusion (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE call_exclusion_attachment');
    }
}
