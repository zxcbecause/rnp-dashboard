<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109093614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI: token usage log and stored weekly reviews';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_usage (id INT AUTO_INCREMENT NOT NULL, feature VARCHAR(32) NOT NULL, model VARCHAR(64) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, cache_read_tokens INT NOT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX idx_ai_usage_created (created_at), INDEX IDX_8C5C6535A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ai_weekly_review (id INT AUTO_INCREMENT NOT NULL, week_start DATE NOT NULL, text LONGTEXT NOT NULL, model VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, org_unit_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_ai_weekly_review_unit_week (org_unit_id, week_start), INDEX IDX_55E79FAD8BC224C3 (org_unit_id), INDEX IDX_55E79FADB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_usage ADD CONSTRAINT FK_8C5C6535A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ai_weekly_review ADD CONSTRAINT FK_55E79FAD8BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_weekly_review ADD CONSTRAINT FK_55E79FADB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_usage DROP FOREIGN KEY FK_8C5C6535A76ED395');
        $this->addSql('ALTER TABLE ai_weekly_review DROP FOREIGN KEY FK_55E79FAD8BC224C3');
        $this->addSql('ALTER TABLE ai_weekly_review DROP FOREIGN KEY FK_55E79FADB03A8386');
        $this->addSql('DROP TABLE ai_usage');
        $this->addSql('DROP TABLE ai_weekly_review');
    }
}
