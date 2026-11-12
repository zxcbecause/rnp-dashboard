<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plan computed from other metrics per period (e.g. 30 % of incoming bookings).
 */
final class Version20261110090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'metric.plan_formula';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE metric ADD plan_formula VARCHAR(1000) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE metric DROP plan_formula');
    }
}
