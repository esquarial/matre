<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add retry tracking columns to test_runs and max_retry_count to settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matre_test_runs ADD original_run_id INT DEFAULT NULL, ADD retry_attempt SMALLINT NOT NULL DEFAULT 0, ADD retry_test_ids TEXT DEFAULT NULL, ADD retry_spawned_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE matre_test_runs ADD CONSTRAINT FK_ORIGINAL_RUN FOREIGN KEY (original_run_id) REFERENCES matre_test_runs(id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_TEST_RUN_ORIGINAL ON matre_test_runs (original_run_id)');

        $this->addSql('ALTER TABLE matre_settings ADD max_retry_count SMALLINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matre_test_runs DROP FOREIGN KEY FK_ORIGINAL_RUN');
        $this->addSql('DROP INDEX IDX_TEST_RUN_ORIGINAL ON matre_test_runs');
        $this->addSql('ALTER TABLE matre_test_runs DROP COLUMN original_run_id, DROP COLUMN retry_attempt, DROP COLUMN retry_test_ids, DROP COLUMN retry_spawned_at');

        $this->addSql('ALTER TABLE matre_settings DROP COLUMN max_retry_count');
    }
}
