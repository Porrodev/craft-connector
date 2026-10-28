<?php

namespace porro\craft\activity;

use Craft;
use craft\db\Query;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\EntryStore;

/**
 * Blocklist or safelist entries in a table, so every server of a site
 * sees the same list.
 */
class DbEntryStore implements EntryStore
{
    public function __construct(private string $table) {}

    public function all(): array
    {
        $rows = (new Query)->from($this->table)->orderBy(['id' => SORT_ASC])->all();

        return array_map(fn (array $row) => Entry::fromArray($row), $rows);
    }

    public function put(Entry $entry): void
    {
        $row = $entry->toArray();

        // One statement, so two people blocking the same IP at once can't collide.
        Craft::$app->getDb()->createCommand()->upsert($this->table, $row, $row, [], false)->execute();
    }

    public function remove(string $cidr): void
    {
        Craft::$app->getDb()->createCommand()->delete($this->table, ['cidr' => $cidr])->execute();
    }
}
