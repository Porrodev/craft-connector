<?php

namespace porro\craft\migrations;

use craft\db\Migration;

/**
 * Fresh installs: the blocklist and safelist tables.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        (new m261028_000000_blocklist_tables)->createTables($this);
        (new m261029_000000_porro_cache)->createTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%porro_blocklist}}');
        $this->dropTableIfExists('{{%porro_safelist}}');
        $this->dropTableIfExists('{{%porro_cache}}');

        return true;
    }
}
