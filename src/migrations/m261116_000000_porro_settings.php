<?php

namespace porro\craft\migrations;

use craft\db\Migration;

/**
 * Where the site key made by "Connect to Porro" in the control panel is
 * kept, encrypted with Craft's security key. A table of its own, so
 * clearing caches never disconnects the site.
 */
class m261116_000000_porro_settings extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%porro_settings}}');

        return true;
    }

    public function createTables(Migration $migration): void
    {
        if ($migration->db->tableExists('{{%porro_settings}}')) {
            return;
        }

        $migration->createTable('{{%porro_settings}}', [
            'key' => $migration->string(64)->notNull(),
            'value' => $migration->text(),
            'PRIMARY KEY([[key]])',
        ]);
    }
}
