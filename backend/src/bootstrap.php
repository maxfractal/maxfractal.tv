<?php

declare(strict_types=1);

require_once __DIR__ . '/ArchiveException.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Maxfractal\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $path = __DIR__ . '/' . $relative;
    if (is_file($path)) {
        require $path;
    }
});
