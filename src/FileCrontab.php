<?php

namespace porro\craft;

use Porro\Core\CrontabIo;

/**
 * A crontab kept in a plain file. Only used when PORRO_CRONTAB_FILE is
 * set, so tests never touch the real crontab of whoever runs them.
 */
class FileCrontab extends CrontabIo
{
    public function __construct(private string $path) {}

    public function available(): bool
    {
        return true;
    }

    public function read(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    public function write(string $contents): bool
    {
        return file_put_contents($this->path, $contents) !== false;
    }
}
