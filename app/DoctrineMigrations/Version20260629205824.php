<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * develop-sync schema changes that were ported "code only" (entities/.orm.yml committed, DB left for a
 * tracked migration). Adds the two columns the ported features require:
 *   - Phase D5: ThemeElementMeta.show_when_empty           (develop 006d0e97)
 *   - Phase D7: DataFieldsMeta.editable_file_extensions    (develop 26dd4715)
 *
 * Scoped to ONLY these two columns on purpose -- `doctrine:schema:update` also reports pre-existing
 * SF7-upgrade drift (Gedmo ext_translations/ext_log_entries VARCHAR(191), fos_user index drops) that is
 * intentionally NOT bundled here; that drift should be reconciled in its own reviewed migration.
 * Supersedes the manual DDL recorded in DEVELOP_SYNC_CHANGELIST.md.
 */
final class Version20260629205824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'develop-sync: add ThemeElementMeta.show_when_empty (D5) + DataFieldsMeta.editable_file_extensions (D7)';
    }

    public function up(Schema $schema): void
    {
        // No explicit column DEFAULT -- matches what the entity mappings generate (see
        // doctrine:schema:update); MySQL backfills existing rows with the implicit type default
        // (0 / '') when adding a NOT NULL column.
        $this->addSql('ALTER TABLE odr_theme_element_meta ADD show_when_empty TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE odr_data_fields_meta ADD editable_file_extensions VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odr_data_fields_meta DROP editable_file_extensions');
        $this->addSql('ALTER TABLE odr_theme_element_meta DROP show_when_empty');
    }
}
