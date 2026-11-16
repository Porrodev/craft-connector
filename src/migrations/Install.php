<?php

namespace porro\craft\migrations;

use craft\db\Migration;

/**
 * Fresh installs: the blocklist and safelist tables, Porro's own cache
 * and where a site key made in the control panel is kept.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        (new m261028_000000_blocklist_tables)->createTables($this);
        (new m261029_000000_porro_cache)->createTables($this);
        (new m261116_000000_porro_settings)->createTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%porro_blocklist}}');
        $this->dropTableIfExists('{{%porro_safelist}}');
        $this->dropTableIfExists('{{%porro_cache}}');
        $this->dropTableIfExists('{{%porro_settings}}');

        return true;
    }
}
