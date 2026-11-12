<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Category of a call exclusion (technical / spam / test / own / other). Earlier requests were all about calls
 * missed for a technical reason.
 */
final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'call_exclusion.category';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE call_exclusion ADD category VARCHAR(16) DEFAULT 'technical' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE call_exclusion DROP category');
    }
}
