<?php

/*
 * Prepended to the test web server so it behaves like PHP-FPM, which can
 * finish the response before the script ends.
 */

if (! function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request(): bool
    {
        flush();

        return true;
    }
}
