<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104082311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, org tree (closure table), metrics, plans, values, actions, sprints, error log, sync runs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE base_function (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, periodicity VARCHAR(16) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, sort INT DEFAULT 0 NOT NULL, metric_id INT NOT NULL, owner_user_id INT DEFAULT NULL, INDEX IDX_8045B5C5A952D583 (metric_id), INDEX IDX_8045B5C52B18554A (owner_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE base_function_check (id INT AUTO_INCREMENT NOT NULL, period_start DATE NOT NULL, done TINYINT NOT NULL, note LONGTEXT DEFAULT NULL, checked_at DATETIME NOT NULL, base_function_id INT NOT NULL, checked_by INT DEFAULT NULL, UNIQUE INDEX uniq_base_function_check_period (base_function_id, period_start), INDEX IDX_624BFB636E217EF9 (base_function_id), INDEX IDX_624BFB6382E3C2DE (checked_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE error_entry (id INT AUTO_INCREMENT NOT NULL, occurred_on DATE NOT NULL, description LONGTEXT NOT NULL, impact_type VARCHAR(16) NOT NULL, impact_amount NUMERIC(14, 2) DEFAULT NULL, cause LONGTEXT DEFAULT NULL, prevention LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, org_unit_id INT NOT NULL, reporter_id INT DEFAULT NULL, owner_id INT DEFAULT NULL, INDEX idx_error_entry_unit_date (org_unit_id, occurred_on), INDEX IDX_40A963A8BC224C3 (org_unit_id), INDEX IDX_40A963AE1CFE6F5 (reporter_id), INDEX IDX_40A963A7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE metric (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, unit VARCHAR(16) NOT NULL, direction VARCHAR(16) NOT NULL, time_agg VARCHAR(16) NOT NULL, tree_agg VARCHAR(16) NOT NULL, formula VARCHAR(1000) DEFAULT NULL, source_type VARCHAR(16) NOT NULL, source_config JSON DEFAULT NULL, granularity VARCHAR(8) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, sort INT DEFAULT 0 NOT NULL, what_measured LONGTEXT DEFAULT NULL, formula_text LONGTEXT DEFAULT NULL, source_text LONGTEXT DEFAULT NULL, period_text LONGTEXT DEFAULT NULL, used_in LONGTEXT DEFAULT NULL, org_unit_id INT NOT NULL, filler_user_id INT DEFAULT NULL, owner_user_id INT DEFAULT NULL, INDEX idx_metric_unit_sort (org_unit_id, sort), UNIQUE INDEX uniq_metric_code (code), INDEX IDX_87D62EE38BC224C3 (org_unit_id), INDEX IDX_87D62EE34CB817E (filler_user_id), INDEX IDX_87D62EE32B18554A (owner_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE metric_link (id INT AUTO_INCREMENT NOT NULL, parent_metric_id INT NOT NULL, child_metric_id INT NOT NULL, UNIQUE INDEX uniq_metric_link (parent_metric_id, child_metric_id), INDEX IDX_B9D6CBE65819FA6D (parent_metric_id), INDEX IDX_B9D6CBE67863B50F (child_metric_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE metric_plan (id INT AUTO_INCREMENT NOT NULL, period_type VARCHAR(8) NOT NULL, period_start DATE NOT NULL, value NUMERIC(18, 4) NOT NULL, set_at DATETIME NOT NULL, metric_id INT NOT NULL, set_by INT DEFAULT NULL, UNIQUE INDEX uniq_metric_plan_period (metric_id, period_type, period_start), INDEX IDX_5220096AA952D583 (metric_id), INDEX IDX_5220096A9009B80A (set_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE metric_threshold (id INT AUTO_INCREMENT NOT NULL, green_from_pct NUMERIC(7, 2) NOT NULL, red_below_pct NUMERIC(7, 2) NOT NULL, metric_id INT DEFAULT NULL, UNIQUE INDEX uniq_metric_threshold_metric (metric_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE metric_value (id INT AUTO_INCREMENT NOT NULL, period_type VARCHAR(8) NOT NULL, period_start DATE NOT NULL, value NUMERIC(18, 4) NOT NULL, origin VARCHAR(8) NOT NULL, entered_at DATETIME NOT NULL, metric_id INT NOT NULL, entered_by INT DEFAULT NULL, UNIQUE INDEX uniq_metric_value_period (metric_id, period_type, period_start), INDEX IDX_9E2BA7A1A952D583 (metric_id), INDEX IDX_9E2BA7A1BFF9CCF (entered_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE org_membership (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(16) NOT NULL, user_id INT NOT NULL, org_unit_id INT NOT NULL, UNIQUE INDEX uniq_org_membership_user_unit (user_id, org_unit_id), INDEX IDX_3A39D41A76ED395 (user_id), INDEX IDX_3A39D418BC224C3 (org_unit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE org_unit (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sort INT DEFAULT 0 NOT NULL, parent_id INT DEFAULT NULL, head_user_id INT DEFAULT NULL, INDEX idx_org_unit_parent_sort (parent_id, sort), INDEX IDX_455318CB727ACA70 (parent_id), INDEX IDX_455318CBFDA6B484 (head_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE org_unit_path (depth SMALLINT UNSIGNED NOT NULL, ancestor_id INT NOT NULL, descendant_id INT NOT NULL, INDEX idx_org_unit_path_descendant (descendant_id, depth), INDEX IDX_BEED7607C671CEA1 (ancestor_id), INDEX IDX_BEED76071844467D (descendant_id), PRIMARY KEY (ancestor_id, descendant_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recurring_task (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, periodicity VARCHAR(16) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, org_unit_id INT NOT NULL, INDEX IDX_FD9376348BC224C3 (org_unit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, family VARCHAR(32) DEFAULT NULL, family_valid DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_9BACE7E1C74F2195 (refresh_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE roadmap_event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(24) NOT NULL, field VARCHAR(64) DEFAULT NULL, old_value LONGTEXT DEFAULT NULL, new_value LONGTEXT DEFAULT NULL, effect VARCHAR(16) DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, roadmap_item_id INT NOT NULL, actor_id INT DEFAULT NULL, INDEX idx_roadmap_event_item_created (roadmap_item_id, created_at), INDEX IDX_9B1DC0A3DB21C47 (roadmap_item_id), INDEX IDX_9B1DC0A310DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE roadmap_item (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, due_date DATE DEFAULT NULL, status VARCHAR(16) NOT NULL, expected_effect LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, metric_id INT NOT NULL, owner_id INT DEFAULT NULL, INDEX idx_roadmap_item_metric_status (metric_id, status), INDEX IDX_A63359FEA952D583 (metric_id), INDEX IDX_A63359FE7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sprint_task (id INT AUTO_INCREMENT NOT NULL, week_start DATE NOT NULL, tag VARCHAR(64) DEFAULT NULL, priority VARCHAR(2) NOT NULL, title VARCHAR(255) NOT NULL, progress_pct SMALLINT UNSIGNED DEFAULT 0 NOT NULL, result_note LONGTEXT DEFAULT NULL, link VARCHAR(1000) DEFAULT NULL, next_step LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, org_unit_id INT NOT NULL, metric_id INT DEFAULT NULL, carried_from_id INT DEFAULT NULL, INDEX idx_sprint_task_unit_week (org_unit_id, week_start), INDEX IDX_69BC74098BC224C3 (org_unit_id), INDEX IDX_69BC7409A952D583 (metric_id), INDEX IDX_69BC7409F3E7C4B8 (carried_from_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sync_run (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(16) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, `rows` INT UNSIGNED DEFAULT 0 NOT NULL, error LONGTEXT DEFAULT NULL, INDEX idx_sync_run_source_started (source, started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(255) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_metric_pref (id INT AUTO_INCREMENT NOT NULL, visible TINYINT DEFAULT 1 NOT NULL, sort INT DEFAULT 0 NOT NULL, user_id INT NOT NULL, metric_id INT NOT NULL, UNIQUE INDEX uniq_user_metric_pref (user_id, metric_id), INDEX IDX_873403B3A76ED395 (user_id), INDEX IDX_873403B3A952D583 (metric_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE base_function ADD CONSTRAINT FK_8045B5C5A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE base_function ADD CONSTRAINT FK_8045B5C52B18554A FOREIGN KEY (owner_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE base_function_check ADD CONSTRAINT FK_624BFB636E217EF9 FOREIGN KEY (base_function_id) REFERENCES base_function (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE base_function_check ADD CONSTRAINT FK_624BFB6382E3C2DE FOREIGN KEY (checked_by) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE error_entry ADD CONSTRAINT FK_40A963A8BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE error_entry ADD CONSTRAINT FK_40A963AE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE error_entry ADD CONSTRAINT FK_40A963A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE metric ADD CONSTRAINT FK_87D62EE38BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE metric ADD CONSTRAINT FK_87D62EE34CB817E FOREIGN KEY (filler_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE metric ADD CONSTRAINT FK_87D62EE32B18554A FOREIGN KEY (owner_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE metric_link ADD CONSTRAINT FK_B9D6CBE65819FA6D FOREIGN KEY (parent_metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE metric_link ADD CONSTRAINT FK_B9D6CBE67863B50F FOREIGN KEY (child_metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE metric_plan ADD CONSTRAINT FK_5220096AA952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE metric_plan ADD CONSTRAINT FK_5220096A9009B80A FOREIGN KEY (set_by) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE metric_threshold ADD CONSTRAINT FK_BDC3CF2FA952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE metric_value ADD CONSTRAINT FK_9E2BA7A1A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE metric_value ADD CONSTRAINT FK_9E2BA7A1BFF9CCF FOREIGN KEY (entered_by) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE org_membership ADD CONSTRAINT FK_3A39D41A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE org_membership ADD CONSTRAINT FK_3A39D418BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE org_unit ADD CONSTRAINT FK_455318CB727ACA70 FOREIGN KEY (parent_id) REFERENCES org_unit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE org_unit ADD CONSTRAINT FK_455318CBFDA6B484 FOREIGN KEY (head_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE org_unit_path ADD CONSTRAINT FK_BEED7607C671CEA1 FOREIGN KEY (ancestor_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE org_unit_path ADD CONSTRAINT FK_BEED76071844467D FOREIGN KEY (descendant_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurring_task ADD CONSTRAINT FK_FD9376348BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_event ADD CONSTRAINT FK_9B1DC0A3DB21C47 FOREIGN KEY (roadmap_item_id) REFERENCES roadmap_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_event ADD CONSTRAINT FK_9B1DC0A310DAF24A FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE roadmap_item ADD CONSTRAINT FK_A63359FEA952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_item ADD CONSTRAINT FK_A63359FE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sprint_task ADD CONSTRAINT FK_69BC74098BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sprint_task ADD CONSTRAINT FK_69BC7409A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sprint_task ADD CONSTRAINT FK_69BC7409F3E7C4B8 FOREIGN KEY (carried_from_id) REFERENCES sprint_task (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_metric_pref ADD CONSTRAINT FK_873403B3A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_metric_pref ADD CONSTRAINT FK_873403B3A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE base_function DROP FOREIGN KEY FK_8045B5C5A952D583');
        $this->addSql('ALTER TABLE base_function DROP FOREIGN KEY FK_8045B5C52B18554A');
        $this->addSql('ALTER TABLE base_function_check DROP FOREIGN KEY FK_624BFB636E217EF9');
        $this->addSql('ALTER TABLE base_function_check DROP FOREIGN KEY FK_624BFB6382E3C2DE');
        $this->addSql('ALTER TABLE error_entry DROP FOREIGN KEY FK_40A963A8BC224C3');
        $this->addSql('ALTER TABLE error_entry DROP FOREIGN KEY FK_40A963AE1CFE6F5');
        $this->addSql('ALTER TABLE error_entry DROP FOREIGN KEY FK_40A963A7E3C61F9');
        $this->addSql('ALTER TABLE metric DROP FOREIGN KEY FK_87D62EE38BC224C3');
        $this->addSql('ALTER TABLE metric DROP FOREIGN KEY FK_87D62EE34CB817E');
        $this->addSql('ALTER TABLE metric DROP FOREIGN KEY FK_87D62EE32B18554A');
        $this->addSql('ALTER TABLE metric_link DROP FOREIGN KEY FK_B9D6CBE65819FA6D');
        $this->addSql('ALTER TABLE metric_link DROP FOREIGN KEY FK_B9D6CBE67863B50F');
        $this->addSql('ALTER TABLE metric_plan DROP FOREIGN KEY FK_5220096AA952D583');
        $this->addSql('ALTER TABLE metric_plan DROP FOREIGN KEY FK_5220096A9009B80A');
        $this->addSql('ALTER TABLE metric_threshold DROP FOREIGN KEY FK_BDC3CF2FA952D583');
        $this->addSql('ALTER TABLE metric_value DROP FOREIGN KEY FK_9E2BA7A1A952D583');
        $this->addSql('ALTER TABLE metric_value DROP FOREIGN KEY FK_9E2BA7A1BFF9CCF');
        $this->addSql('ALTER TABLE org_membership DROP FOREIGN KEY FK_3A39D41A76ED395');
        $this->addSql('ALTER TABLE org_membership DROP FOREIGN KEY FK_3A39D418BC224C3');
        $this->addSql('ALTER TABLE org_unit DROP FOREIGN KEY FK_455318CB727ACA70');
        $this->addSql('ALTER TABLE org_unit DROP FOREIGN KEY FK_455318CBFDA6B484');
        $this->addSql('ALTER TABLE org_unit_path DROP FOREIGN KEY FK_BEED7607C671CEA1');
        $this->addSql('ALTER TABLE org_unit_path DROP FOREIGN KEY FK_BEED76071844467D');
        $this->addSql('ALTER TABLE recurring_task DROP FOREIGN KEY FK_FD9376348BC224C3');
        $this->addSql('ALTER TABLE roadmap_event DROP FOREIGN KEY FK_9B1DC0A3DB21C47');
        $this->addSql('ALTER TABLE roadmap_event DROP FOREIGN KEY FK_9B1DC0A310DAF24A');
        $this->addSql('ALTER TABLE roadmap_item DROP FOREIGN KEY FK_A63359FEA952D583');
        $this->addSql('ALTER TABLE roadmap_item DROP FOREIGN KEY FK_A63359FE7E3C61F9');
        $this->addSql('ALTER TABLE sprint_task DROP FOREIGN KEY FK_69BC74098BC224C3');
        $this->addSql('ALTER TABLE sprint_task DROP FOREIGN KEY FK_69BC7409A952D583');
        $this->addSql('ALTER TABLE sprint_task DROP FOREIGN KEY FK_69BC7409F3E7C4B8');
        $this->addSql('ALTER TABLE user_metric_pref DROP FOREIGN KEY FK_873403B3A76ED395');
        $this->addSql('ALTER TABLE user_metric_pref DROP FOREIGN KEY FK_873403B3A952D583');
        $this->addSql('DROP TABLE base_function');
        $this->addSql('DROP TABLE base_function_check');
        $this->addSql('DROP TABLE error_entry');
        $this->addSql('DROP TABLE metric');
        $this->addSql('DROP TABLE metric_link');
        $this->addSql('DROP TABLE metric_plan');
        $this->addSql('DROP TABLE metric_threshold');
        $this->addSql('DROP TABLE metric_value');
        $this->addSql('DROP TABLE org_membership');
        $this->addSql('DROP TABLE org_unit');
        $this->addSql('DROP TABLE org_unit_path');
        $this->addSql('DROP TABLE recurring_task');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE roadmap_event');
        $this->addSql('DROP TABLE roadmap_item');
        $this->addSql('DROP TABLE sprint_task');
        $this->addSql('DROP TABLE sync_run');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE user_metric_pref');
    }
}
