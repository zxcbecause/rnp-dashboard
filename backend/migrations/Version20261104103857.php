<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104103857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Base functions belong to an org unit (metric optional) and get a textual deadline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE base_function DROP FOREIGN KEY `FK_8045B5C5A952D583`');
        // Existing rows take the org unit of their metric before the column becomes NOT NULL.
        $this->addSql('ALTER TABLE base_function ADD due_text VARCHAR(255) DEFAULT NULL, ADD org_unit_id INT DEFAULT NULL, CHANGE metric_id metric_id INT DEFAULT NULL');
        $this->addSql('UPDATE base_function bf JOIN metric m ON m.id = bf.metric_id SET bf.org_unit_id = m.org_unit_id');
        $this->addSql('ALTER TABLE base_function MODIFY org_unit_id INT NOT NULL');
        $this->addSql('ALTER TABLE base_function ADD CONSTRAINT FK_8045B5C5A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE base_function ADD CONSTRAINT FK_8045B5C58BC224C3 FOREIGN KEY (org_unit_id) REFERENCES org_unit (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_base_function_unit_active ON base_function (org_unit_id, is_active)');
        $this->addSql('CREATE INDEX IDX_8045B5C58BC224C3 ON base_function (org_unit_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE base_function DROP FOREIGN KEY FK_8045B5C5A952D583');
        $this->addSql('ALTER TABLE base_function DROP FOREIGN KEY FK_8045B5C58BC224C3');
        $this->addSql('DROP INDEX idx_base_function_unit_active ON base_function');
        $this->addSql('DROP INDEX IDX_8045B5C58BC224C3 ON base_function');
        $this->addSql('ALTER TABLE base_function DROP due_text, DROP org_unit_id, CHANGE metric_id metric_id INT NOT NULL');
        $this->addSql('ALTER TABLE base_function ADD CONSTRAINT `FK_8045B5C5A952D583` FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
    }
}
