<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015170522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'dev: add ability for default sort fields to sort DESC';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odr_data_type_special_fields ADD sort_desc TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odr_data_type_special_fields DROP sort_desc');
    }
}
