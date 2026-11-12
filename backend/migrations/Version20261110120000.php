<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cancellation moment of a booking (the platform has none): kept from the first sync that sees it cancelled.
 * Bookings already cancelled get their lastModification — the best available guess.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'raw_platform_booking.cancelled_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raw_platform_booking ADD cancelled_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE raw_platform_booking SET cancelled_at = COALESCE(last_modification, synced_at) WHERE status = 'CANCELLED'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raw_platform_booking DROP cancelled_at');
    }
}
