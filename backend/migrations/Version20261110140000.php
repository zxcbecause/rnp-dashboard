<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI explanations of bell alerts (feature D), reused while the metric's figures stay the same.
 */
final class Version20261110140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ai_anomaly_explanation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_anomaly_explanation (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL, period_start DATE NOT NULL, input_hash VARCHAR(40) NOT NULL, text LONGTEXT NOT NULL, model VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, metric_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_ai_anomaly_metric_kind_period (metric_id, kind, period_start), INDEX IDX_8754A7B3A952D583 (metric_id), INDEX IDX_8754A7B3B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_anomaly_explanation ADD CONSTRAINT FK_8754A7B3A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_anomaly_explanation ADD CONSTRAINT FK_8754A7B3B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_anomaly_explanation');
    }
}
