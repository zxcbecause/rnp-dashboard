<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nightly snapshot of the bookings created on a day (as of 23:55 Madrid).
 */
final class Version20261110110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'booking_day_snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booking_day_snapshot (id INT AUTO_INCREMENT NOT NULL, snapshot_date DATE NOT NULL, booking_id INT NOT NULL, status VARCHAR(32) DEFAULT NULL, portal VARCHAR(128) DEFAULT NULL, total NUMERIC(14, 2) DEFAULT NULL, rental_price NUMERIC(14, 2) DEFAULT NULL, currency VARCHAR(8) DEFAULT NULL, creation_date DATETIME DEFAULT NULL, arrival_date DATETIME DEFAULT NULL, departure_date DATETIME DEFAULT NULL, last_modification DATETIME DEFAULT NULL, accommodation_id INT DEFAULT NULL, taken_at DATETIME NOT NULL, UNIQUE INDEX uniq_booking_day_snapshot (snapshot_date, booking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE booking_day_snapshot');
    }
}
