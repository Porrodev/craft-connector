<?php

/*
 * Router for the test web server that makes it behave like PHP-FPM, which
 * can finish the response before the script ends. (A router rather than
 * auto_prepend_file: before PHP 8.4 the built-in server skips that for
 * router scripts.)
 */

if (! function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request(): bool
    {
        flush();

        return true;
    }
}

// Before PHP 8.4 the built-in server names the requested path as the
// script for a path with a dot in it (/.env), and Yii can't find its entry
// script. PHP-FPM always names index.php.
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';

require $_SERVER['DOCUMENT_ROOT'].'/index.php';
