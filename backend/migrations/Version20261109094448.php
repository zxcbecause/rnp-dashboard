<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109094448 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deviation notes: reason and action per metric and period';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE metric_deviation_note (id INT AUTO_INCREMENT NOT NULL, period_type VARCHAR(8) NOT NULL, period_start DATE NOT NULL, reason LONGTEXT NOT NULL, action LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, metric_id INT NOT NULL, author_id INT DEFAULT NULL, UNIQUE INDEX uniq_metric_deviation_note_period (metric_id, period_type, period_start), INDEX IDX_2A941100A952D583 (metric_id), INDEX IDX_2A941100F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE metric_deviation_note ADD CONSTRAINT FK_2A941100A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE metric_deviation_note ADD CONSTRAINT FK_2A941100F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE metric_deviation_note DROP FOREIGN KEY FK_2A941100A952D583');
        $this->addSql('ALTER TABLE metric_deviation_note DROP FOREIGN KEY FK_2A941100F675F31B');
        $this->addSql('DROP TABLE metric_deviation_note');
    }
}
