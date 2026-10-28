<?php

namespace porro\craft\variables;

use porro\craft\activity\Confirm;

/**
 * `craft.porro` in the plugin's templates: what each block and safelist
 * button asks before it acts.
 */
class PorroVariable
{
    /**
     * @return array{title: string, body: string, button: string, danger: bool}
     */
    public function confirmBlock(string $target, int $days = 0): array
    {
        return Confirm::block($target, $days);
    }

    /**
     * @return array{title: string, body: string, button: string, danger: bool}
     */
    public function confirmSafelist(string $ip): array
    {
        return Confirm::safelist($ip);
    }

    public function confirmScript(): string
    {
        return Confirm::script();
    }
}
