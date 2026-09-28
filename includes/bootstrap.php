<?php
declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Loaded by every page and API endpoint. It:
 *  - defines the project root,
 *  - registers a simple autoloader for the classes in includes/,
 *  - loads helper functions and exception classes,
 *  - configures safe error handling (no raw PHP errors shown to users).
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/Exceptions.php';

spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = AppConfig::load();

error_reporting(E_ALL);
if ($config->debug) {
    // Local development only: show errors on screen.
    ini_set('display_errors', '1');
} else {
    // Production: never leak raw PHP errors to the browser.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
