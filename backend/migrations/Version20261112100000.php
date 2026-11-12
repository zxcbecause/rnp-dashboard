<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Platform accommodations left out of every metric (test listings).
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'excluded_accommodation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE excluded_accommodation (accommodation_id INT NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (accommodation_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE excluded_accommodation');
    }
}
