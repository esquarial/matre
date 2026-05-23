<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auto_report_for_individual_runs to settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matre_settings ADD auto_report_for_individual_runs TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matre_settings DROP COLUMN auto_report_for_individual_runs');
    }
}
