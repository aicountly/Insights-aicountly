<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * PSR-4 style autoloader for Aicountly\Api\*, mapped onto server-php/src.
 *
 * There is no composer here on purpose: this API has no third-party
 * dependencies, and a vendor directory per product would be megabytes of
 * nothing for a deploy that is an rsync. The same choice Sales, Purchases,
 * Billing and POS make.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Aicountly\\Api\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    // require_once, not require. This file lives under the same prefix it
    // serves, so a lookup for Aicountly\Api\Autoload resolves to THIS file: a
    // plain require would re-run it, register a second copy of this closure,
    // and every subsequent miss would double the number of registered
    // autoloaders until the process stopped responding.
    if (is_file($path)) {
        require_once $path;
    }
});
