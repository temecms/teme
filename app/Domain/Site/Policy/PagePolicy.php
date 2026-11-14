<?php

declare(strict_types=1);

namespace App\Domain\Site\Policy;

use UnexpectedValueException;

/**
 * A public page's content security policy as it is drawn (I-3375): core
 * notes the hash of each inline script it prints itself, site code adds
 * the hashes and origins worked out from its saved code, and the theme its
 * declared origins. header() writes the policy the page is stored with.
 * Nothing is ever read back from the drawn HTML, so a script someone
 * injected never gets a hash. Started afresh for each page.
 */
final class PagePolicy
{
    /** A theme's or a plugin's kind of origin, and the directive it joins. */
    public const array KINDS = [
        'script' => 'script-src', 'style' => 'style-src', 'font' => 'font-src',
        'img' => 'img-src', 'connect' => 'connect-src', 'frame' => 'frame-src',
    ];

    /** The directives an origin an administrator declared on site code joins (D6). */
    public const array DECLARED = ['script-src', 'style-src', 'font-src', 'img-src', 'media-src', 'connect-src', 'frame-src', 'form-action'];

    /** What a policy in a <meta> can't carry (CSP3, the meta element). */
    private const array HEADER_ONLY = ['frame-ancestors', 'report-uri', 'report-to', 'sandbox'];

    /** An origin source: a scheme, then nothing that could end it or the policy (a space, a quote, ;, , or a control character). */
    private const string ORIGIN = '~^[a-z][a-z0-9+.-]*://[^\s\'";,\x00-\x1F\x7F]+$~iD';

    /** @var array<string, true> */
    private array $scripts = [];

    /** @var array<string, true> */
    private array $handlers = [];

    /** @var array<string, array<string, true>> directive => origin => true */
    private array $origins = [];

    public function start(): void
    {
        $this->scripts = [];
        $this->handlers = [];
        $this->origins = [];
    }

    /** Allows this inline script's text, exactly as it is printed. */
    public function allowScript(string $text): void
    {
        // A browser hashes the text after its parser turned CR LF and CR into LF (I-3375).
        $this->scripts[self::hash(str_replace(["\r\n", "\r"], "\n", $text))] = true;
    }

    /** @param  list<string>  $hashes  as hash() gives them */
    public function allowScriptHashes(array $hashes): void
    {
        foreach ($hashes as $hash) {
            $this->scripts[$hash] = true;
        }
    }

    /** @param  list<string>  $hashes  of on* attribute values, as hash() gives them */
    public function allowHandlerHashes(array $hashes): void
    {
        foreach ($hashes as $hash) {
            $this->handlers[$hash] = true;
        }
    }

    /** @param  list<string>  $origins  each https://host, already checked */
    public function allowOrigins(string $directive, array $origins): void
    {
        foreach ($origins as $origin) {
            $this->origins[$directive][$origin] = true;
        }
    }

    /**
     * A CSP hash source for this text: 'sha256-' and the base64 of its
     * SHA-256, quoted.
     */
    public static function hash(string $text): string
    {
        return "'sha256-".base64_encode(hash('sha256', $text, true))."'";
    }

    /**
     * The whole policy, for the header: the same for every reader of the
     * page, so it is stored with it. $here is the scheme and host the page
     * was asked at, against which the media disk's origin is compared.
     */
    public function header(string $here): string
    {
        $self = "'self'";
        $asset = PolicySources::asset($here);
        $media = PolicySources::media($here);
        $handlers = $this->handlers === [] ? [] : ["'unsafe-hashes'", ...array_keys($this->handlers)];

        $directives = [
            'default-src' => [$self],
            'script-src' => [$self, ...$asset, ...array_keys($this->scripts), ...$handlers],
            'style-src' => [$self, "'unsafe-inline'", ...$asset],
            'img-src' => [$self, 'data:', ...$asset, ...$media],
            'media-src' => [$self, ...$media],
            'font-src' => [$self, ...$asset],
            'frame-src' => [$self, ...PolicySources::embedFrames()],
            'connect-src' => [$self],
            'form-action' => [$self],
            'object-src' => ["'none'"],
            'base-uri' => [$self],
            'frame-ancestors' => [$self],
            'report-uri' => [PolicyReports::url()],
            'report-to' => [PolicyReports::GROUP],
        ];

        foreach ($this->origins as $directive => $origins) {
            $listed = array_values(array_filter(array_map(strval(...), array_keys($origins)), self::fits(...)));
            sort($listed);
            $directives[$directive] = [...($directives[$directive] ?? [$self]), ...$listed];
        }

        $parts = [];

        foreach ($directives as $name => $sources) {
            $parts[] = $name.' '.implode(' ', array_values(array_unique($sources)));
        }

        return implode('; ', $parts);
    }

    /**
     * Whether an origin can stand in the header as one source; one that
     * can't is reported and left out, never repaired (I-3375).
     */
    private static function fits(string $origin): bool
    {
        if (preg_match(self::ORIGIN, $origin) === 1) {
            return true;
        }

        report(new UnexpectedValueException('A source was left out of the public content security policy, as it could change the policy: '.json_encode($origin, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'.'));

        return false;
    }

    /**
     * A stored header's policy as a <meta> may carry it: without the
     * directives only a header can carry.
     */
    public static function forMeta(string $header): string
    {
        $kept = [];

        foreach (explode(';', $header) as $directive) {
            $directive = trim($directive);
            $name = strtolower((string) strtok($directive, ' '));

            if ($directive !== '' && ! in_array($name, self::HEADER_ONLY, true)) {
                $kept[] = $directive;
            }
        }

        return implode('; ', $kept);
    }
}
