<?php

/**
 * What a production site needs before it serves a request or starts a role
 * (I-375): its own APP_KEY, an address with a host, sessions in the
 * database, and over https a Secure __Host- session cookie and an HSTS
 * value Teme would send. Every problem is named at once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\Backups\BackupMode;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use SensitiveParameter;

final class StartRules
{
    /**
     * The commands that start a role; a web request starts one too. Build
     * steps and an operator's tools are none of them.
     *
     * @var list<string>
     */
    public const array ROLE_COMMANDS = [
        'config:cache',
        'optimize',
        'queue:work',
        'queue:listen',
        'schedule:work',
        'schedule:run',
        'teme:deploy',
    ];

    /**
     * @throws StartRefused when a production site's web request or role meets a problem
     */
    public static function enforce(Application $app): void
    {
        if (! $app->isProduction()) {
            return;
        }

        if ($app->runningInConsole() && ! $app->runningConsoleCommand(self::ROLE_COMMANDS)) {
            return;
        }

        $problems = self::problems($app->make(Repository::class));

        if ($problems !== []) {
            throw StartRefused::because($problems);
        }
    }

    /**
     * Each thing that stops a production site with this configuration from
     * starting, one sentence each and never a secret's value; empty when
     * nothing does.
     *
     * @return list<string>
     */
    public static function problems(Repository $config): array
    {
        $problems = DatabaseAddress::problems($config);
        $key = (string) $config->get('app.key');
        $cipher = (string) $config->get('app.cipher');
        $url = (string) $config->get('app.url');

        if ($key === '') {
            $problems[] = 'APP_KEY is empty. Teme never makes one: set APP_KEY, or APP_KEY_FILE, in the server\'s environment. To make a key: echo "base64:$(openssl rand -base64 32)"';
        } elseif (! Encrypter::supported(self::keyBytes($key), $cipher)) {
            $problems[] = "APP_KEY isn't a key for {$cipher}: write base64: followed by 32 random bytes in base64, as echo \"base64:\$(openssl rand -base64 32)\" makes one.";
        }

        // TEME_BACKUPS names a mode Teme knows (BackupMode); refused here, as the other settings are, not at boot.
        $backups = BackupMode::problemFor($config->get('teme.backup_mode'));

        if ($backups !== null) {
            $problems[] = $backups;
        }

        if ((string) parse_url($url, PHP_URL_HOST) === '') {
            $problems[] = "APP_URL has no host. Set it to the site's address, such as https://example.com.";
        }

        $driver = (string) $config->get('session.driver');

        if ($driver !== 'database') {
            $problems[] = "SESSION_DRIVER is {$driver}. A production site keeps sessions in the database (SESSION_DRIVER=database), so ending a person's sessions ends every one of them.";
        }

        if (! SecureTransport::required(true, $url)) {
            return $problems;
        }

        if ($config->get('session.secure') !== true) {
            $problems[] = "SESSION_SECURE_COOKIE isn't true, but APP_URL is https: the session cookie must be Secure. Remove SESSION_SECURE_COOKIE, or set it to true.";
        }

        $cookie = (string) $config->get('session.cookie');

        if (! str_starts_with($cookie, '__Host-')) {
            $problems[] = "SESSION_COOKIE is {$cookie}. Over https the session cookie's name begins __Host-: remove SESSION_COOKIE, or begin it with __Host-.";
        }

        $domain = (string) $config->get('session.domain');

        if ($domain !== '') {
            $problems[] = "SESSION_DOMAIN is {$domain}. A __Host- cookie names no domain: remove SESSION_DOMAIN.";
        }

        $path = (string) $config->get('session.path');

        if ($path !== '/') {
            $problems[] = "SESSION_PATH is {$path}. A __Host- cookie's path is /: remove SESSION_PATH.";
        }

        $hsts = trim((string) $config->get('teme.deployment.hsts'));

        if ($hsts !== '' && preg_match(SecureTransport::HSTS_FORM, $hsts) !== 1) {
            $problems[] = "TEME_HSTS is \"{$hsts}\", which isn't a Strict-Transport-Security value: write max-age= and a number of seconds, then ; includeSubDomains and ; preload if wanted, or leave it empty to send none.";
        }

        return $problems;
    }

    /**
     * The key's bytes, read as Laravel reads APP_KEY, but strictly: base64:
     * and valid base64, or the raw string.
     */
    private static function keyBytes(#[SensitiveParameter] string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
