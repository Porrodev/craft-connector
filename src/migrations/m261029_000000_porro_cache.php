<?php

namespace porro\craft\migrations;

use craft\db\Migration;

/**
 * Porro's own cache table: suspicious activity, the Cloudflare connection
 * and nginx check-ins. Separate from Craft's cache so clear-caches on
 * deploy doesn't wipe them. The shape yii\caching\DbCache expects.
 */
class m261029_000000_porro_cache extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%porro_cache}}');

        return true;
    }

    public function createTables(Migration $migration): void
    {
        if ($migration->db->tableExists('{{%porro_cache}}')) {
            return;
        }

        $migration->createTable('{{%porro_cache}}', [
            'id' => $migration->string(128)->notNull(),
            'expire' => $migration->integer(),
            'data' => $migration->binary(),
            'PRIMARY KEY([[id]])',
        ]);
        $migration->createIndex(null, '{{%porro_cache}}', ['expire']);
    }
}
