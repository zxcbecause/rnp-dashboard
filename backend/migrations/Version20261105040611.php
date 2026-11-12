<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Raw snapshot tables of the Bitrix24 integration.
 */
final class Version20261105040611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bitrix24 raw snapshots: owner-pipeline deals, deal stages, stage history, call activities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE raw_bitrix_call (id INT NOT NULL, direction SMALLINT DEFAULT NULL, missed TINYINT NOT NULL, completed TINYINT NOT NULL, start_time DATETIME DEFAULT NULL, end_time DATETIME DEFAULT NULL, owner_type_id INT DEFAULT NULL, owner_id INT DEFAULT NULL, responsible_id INT DEFAULT NULL, last_updated DATETIME DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, INDEX idx_raw_bx_call_start (start_time), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE raw_bitrix_deal (id INT NOT NULL, category_id INT DEFAULT NULL, stage_id VARCHAR(64) DEFAULT NULL, stage_semantic VARCHAR(1) DEFAULT NULL, source_id VARCHAR(128) DEFAULT NULL, owner_source VARCHAR(128) DEFAULT NULL, utm_source VARCHAR(255) DEFAULT NULL, assigned_by_id INT DEFAULT NULL, lead_id INT DEFAULT NULL, closed TINYINT NOT NULL, date_create DATETIME DEFAULT NULL, date_modify DATETIME DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, INDEX idx_raw_bx_deal_created (date_create), INDEX idx_raw_bx_deal_category (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE raw_bitrix_deal_stage (id VARCHAR(64) NOT NULL, category_id INT NOT NULL, name VARCHAR(255) DEFAULT NULL, sort INT NOT NULL, semantics VARCHAR(1) DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE raw_bitrix_deal_stage_history (id INT NOT NULL, deal_id INT NOT NULL, category_id INT DEFAULT NULL, stage_id VARCHAR(64) DEFAULT NULL, stage_semantic VARCHAR(1) DEFAULT NULL, type_id SMALLINT DEFAULT NULL, created_time DATETIME DEFAULT NULL, payload JSON NOT NULL, synced_at DATETIME NOT NULL, INDEX idx_raw_bx_stage_hist_deal (deal_id), INDEX idx_raw_bx_stage_hist_time (created_time), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE raw_bitrix_call');
        $this->addSql('DROP TABLE raw_bitrix_deal');
        $this->addSql('DROP TABLE raw_bitrix_deal_stage');
        $this->addSql('DROP TABLE raw_bitrix_deal_stage_history');
    }
}
