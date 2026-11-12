<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104090452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform API raw snapshots (bookings, accommodations, housekeeping tasks, tickets) and sync_run.scope';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE raw_platform_accommodation (id INT NOT NULL, name VARCHAR(255) DEFAULT NULL, status VARCHAR(16) DEFAULT NULL, type VARCHAR(32) DEFAULT NULL, city VARCHAR(128) DEFAULT NULL, sales_activation_date DATE DEFAULT NULL, sales_deactivation_date DATE DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE raw_platform_booking (id INT NOT NULL, status VARCHAR(32) DEFAULT NULL, portal VARCHAR(128) DEFAULT NULL, total NUMERIC(14, 2) DEFAULT NULL, currency VARCHAR(8) DEFAULT NULL, arrival_date DATETIME DEFAULT NULL, departure_date DATETIME DEFAULT NULL, creation_date DATETIME DEFAULT NULL, last_modification DATETIME DEFAULT NULL, accommodation_id INT DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, INDEX idx_raw_booking_creation (creation_date), INDEX idx_raw_booking_arrival (arrival_date), INDEX idx_raw_booking_departure (departure_date), INDEX idx_raw_booking_modified (last_modification), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE raw_platform_housekeeping_task (id INT NOT NULL, status VARCHAR(16) NOT NULL, type VARCHAR(32) NOT NULL, accommodation_id INT DEFAULT NULL, booking_id INT DEFAULT NULL, assignee_id INT DEFAULT NULL, due_date DATETIME DEFAULT NULL, creation_date DATETIME DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, INDEX idx_raw_hk_task_due (due_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE raw_platform_ticket (id INT NOT NULL, title VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, type VARCHAR(16) NOT NULL, priority VARCHAR(16) NOT NULL, accommodation_id INT DEFAULT NULL, price NUMERIC(14, 2) DEFAULT NULL, creation_date DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, INDEX idx_raw_ticket_creation (creation_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sync_run ADD scope VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sync_run_scope_status ON sync_run (source, scope, status, started_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE raw_platform_accommodation');
        $this->addSql('DROP TABLE raw_platform_booking');
        $this->addSql('DROP TABLE raw_platform_housekeeping_task');
        $this->addSql('DROP TABLE raw_platform_ticket');
        $this->addSql('DROP INDEX idx_sync_run_scope_status ON sync_run');
        $this->addSql('ALTER TABLE sync_run DROP scope');
    }
}
