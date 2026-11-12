<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Requests to exclude a missed call from the statistics, with the head's decision.
 */
final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'call_exclusion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE call_exclusion (id INT AUTO_INCREMENT NOT NULL, call_id INT NOT NULL, call_started_at DATETIME NOT NULL, responsible_id INT DEFAULT NULL, reason LONGTEXT NOT NULL, status VARCHAR(16) NOT NULL, requested_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, decision_note LONGTEXT DEFAULT NULL, org_unit_id INT NOT NULL, requested_by_id INT DEFAULT NULL, decided_by_id INT DEFAULT NULL, INDEX idx_call_exclusion_unit_status (org_unit_id, status), UNIQUE INDEX uniq_call_exclusion_call (call_id), INDEX IDX_2613549F8BC224C3 (org_unit_id), INDEX IDX_2613549F4DA1E751 (requested_by_id), INDEX IDX_2613549FE26B496B (decided_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE call_exclusion ADD CONSTRAINT FK_2613549F8BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE call_exclusion ADD CONSTRAINT FK_2613549F4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE call_exclusion ADD CONSTRAINT FK_2613549FE26B496B FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE call_exclusion DROP FOREIGN KEY FK_2613549F8BC224C3');
        $this->addSql('ALTER TABLE call_exclusion DROP FOREIGN KEY FK_2613549F4DA1E751');
        $this->addSql('ALTER TABLE call_exclusion DROP FOREIGN KEY FK_2613549FE26B496B');
        $this->addSql('DROP TABLE call_exclusion');
    }
}
