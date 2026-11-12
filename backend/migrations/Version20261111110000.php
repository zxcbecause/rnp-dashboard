<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Metrics that show the average per day next to their totals in the RNP (calls of CS).
 */
final class Version20261111110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'metric.show_daily_avg';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE metric ADD show_daily_avg TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE metric DROP show_daily_avg');
    }
}
