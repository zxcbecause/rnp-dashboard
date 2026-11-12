<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tokens of Push API sources.
 */
final class Version20261105062752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Push API source tokens (ingest_token)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ingest_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(32) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_hint VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, org_unit_id INT NOT NULL, UNIQUE INDEX uniq_ingest_token_name (name), UNIQUE INDEX uniq_ingest_token_hash (token_hash), INDEX IDX_B77678658BC224C3 (org_unit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ingest_token ADD CONSTRAINT FK_B77678658BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_token DROP FOREIGN KEY FK_B77678658BC224C3');
        $this->addSql('DROP TABLE ingest_token');
    }
}
