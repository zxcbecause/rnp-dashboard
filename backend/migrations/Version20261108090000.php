<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Booking detail-card fields: rentalPrice and portalCommission are not in the platform list.
 */
final class Version20261108090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'raw_platform_booking: rental_price, portal_commission, detail_modification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raw_platform_booking ADD rental_price NUMERIC(14, 2) DEFAULT NULL, ADD portal_commission NUMERIC(14, 2) DEFAULT NULL, ADD detail_modification DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raw_platform_booking DROP rental_price, DROP portal_commission, DROP detail_modification');
    }
}
