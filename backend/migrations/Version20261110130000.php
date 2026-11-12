<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow-up lookup of lost calls: calls of the same CRM entity after a missed one.
 */
final class Version20261110130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'raw_bitrix_call index by CRM entity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_raw_bx_call_owner ON raw_bitrix_call (owner_type_id, owner_id, start_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_raw_bx_call_owner ON raw_bitrix_call');
    }
}
