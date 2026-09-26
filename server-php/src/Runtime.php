<?php

// NO strict_types, NO 8.1 syntax, NO type declarations beyond what PHP 7.0
// accepts. THIS FILE HAS TO PARSE ON AN INTERPRETER TOO OLD TO RUN THE REST OF
// THE APPLICATION, because saying so is its entire job.
//
// What went wrong without it: a fresh vhost came up on an older PHP than the
// product needs. `GET /api/health` answered 200, because nothing on that path
// uses newer syntax. Every real endpoint answered a bare 500 with a correlation
// id, because `Auth` and `Http` are loaded on the routed path and failed to
// parse — and a ParseError is a Throwable, so the catch-all swallowed it into
// "Something went wrong handling that request." Nobody without shell access to
// the error log could tell a broken deployment from broken code.

namespace Aicountly\Api;

/**
 * What this application needs from the server it is running on.
 */
final class Runtime
{
    /**
     * Auth and Http use `readonly` promotion and `never`, both PHP 8.1.
     * Raising this means checking those files first, not just the number.
     */
    const MINIMUM_PHP = '8.1.0';

    /**
     * Extensions, and what stops working without each one. The reason is
     * reported, because "ext-zip missing" means nothing to the person who has
     * to switch it on in cPanel.
     *
     * @var array<string, string>
     */
    const REQUIRED_EXTENSIONS = [
        'curl'      => 'reading figures from Books, Inventory and Manage, and validating sessions with the portal',
        'pdo_pgsql' => 'this product\'s own database',
        'json'      => 'every request and response',
        'mbstring'  => 'names and labels that are not plain ASCII',
        'zip'       => 'XLSX exports',
    ];

    /**
     * Everything this server cannot do, in the order it matters.
     *
     * @return array<int, array<string, string>> empty when the server is fit to run the product
     */
    public static function unmet()
    {
        $problems = [];

        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
            $problems[] = [
                'requirement' => 'PHP ' . self::MINIMUM_PHP . ' or newer',
                'found'       => 'PHP ' . PHP_VERSION,
                'needed_for'  => 'the application itself — it will not parse on this version',
                'fix'         => 'Set this domain to PHP ' . self::MINIMUM_PHP
                    . ' or newer in cPanel (Software -> Select PHP Version / MultiPHP Manager), for this domain specifically.',
            ];
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension => $purpose) {
            if (!extension_loaded($extension)) {
                $problems[] = [
                    'requirement' => 'PHP extension ' . $extension,
                    'found'       => 'not loaded',
                    'needed_for'  => $purpose,
                    'fix'         => 'Enable ' . $extension
                        . ' in cPanel (Software -> Select PHP Version -> Extensions) for this domain.',
                ];
            }
        }

        return $problems;
    }

    /**
     * The health endpoint's runtime block.
     *
     * The PHP version is reported whether or not anything is wrong. It is the
     * one fact that turns "the API returns 500" into a five-second diagnosis,
     * and it is already visible in most server banners.
     *
     * @return array<string, mixed>
     */
    public static function report()
    {
        $problems = self::unmet();

        return [
            'php'          => PHP_VERSION,
            'php_required' => self::MINIMUM_PHP,
            'ok'           => $problems === [],
            'problems'     => $problems,
        ];
    }
}
