<?php

namespace porro\craft\migrations;

use craft\db\Migration;

/**
 * The blocklist and safelist, for sites that installed Porro before
 * blocking existed.
 */
class m261028_000000_blocklist_tables extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%porro_blocklist}}');
        $this->dropTableIfExists('{{%porro_safelist}}');

        return true;
    }

    public function createTables(Migration $migration): void
    {
        foreach (['{{%porro_blocklist}}', '{{%porro_safelist}}'] as $table) {
            if ($migration->db->tableExists($table)) {
                continue;
            }

            $migration->createTable($table, [
                'id' => $migration->primaryKey(),
                // Normalised: "203.0.113.0/24" or "2001:db8::1".
                'cidr' => $migration->string(64)->notNull()->unique(),
                'note' => $migration->string()->notNull()->defaultValue(''),
                'source' => $migration->string(16)->notNull()->defaultValue('manual'),
                'added_at' => $migration->bigInteger()->notNull(),
                'expires_at' => $migration->bigInteger()->null(),
                'added_by' => $migration->string()->null(),
            ]);
            $migration->createIndex(null, $table, ['expires_at']);
        }
    }
}
