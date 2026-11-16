<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Plugins\Manifest;

/**
 * One plain-text file a plugin declares at the site's root under `rootFiles`
 * (docs/guides/developers/plugins/root-files.md): its name and body, each a
 * literal or filled from one of the plugin's own text settings. Core serves
 * it; no plugin code runs.
 */
final readonly class RootFile
{
    /** A root file's name: 8 to 128 letters, digits and hyphens, then .txt. */
    public const string NAME = '/^[A-Za-z0-9-]{8,128}\.txt$/';

    /** A name or body filled from a setting: exactly `{key}`, the setting's key. */
    public const string FROM_SETTING = '/^\{([a-z][a-z0-9_]*)\}$/';

    /** The most root files one plugin may declare. */
    public const int MOST = 2;

    /** The longest literal body, in bytes. */
    public const int MOST_BYTES = 1024;

    /**
     * @param  string  $owner  the plugin's Composer name
     * @param  string  $name  the declared name: a literal, or `{key}.txt`
     * @param  string  $body  the declared body: a literal, or `{key}`
     */
    public function __construct(
        public string $owner,
        public string $id,
        public string $name,
        public string $body,
    ) {}

    /**
     * Every root file this manifest declares, as the file says them, in its
     * order; an entry that isn't an object of strings is left out.
     *
     * @return list<self>
     */
    public static function declaredIn(Manifest $manifest): array
    {
        $declared = $manifest->raw()['rootFiles'] ?? null;

        if (! is_array($declared) || ! array_is_list($declared)) {
            return [];
        }

        $files = [];

        foreach ($declared as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && is_string($entry['name'] ?? null) && is_string($entry['body'] ?? null)) {
                $files[] = new self($manifest->name(), $entry['id'], $entry['name'], $entry['body']);
            }
        }

        return $files;
    }

    /**
     * The key of the setting the name is filled from, or null for a literal name.
     */
    public function nameSetting(): ?string
    {
        return str_ends_with($this->name, '.txt') ? self::settingKey(substr($this->name, 0, -4)) : null;
    }

    /**
     * The key of the setting the body is filled from, or null for a literal body.
     */
    public function bodySetting(): ?string
    {
        return self::settingKey($this->body);
    }

    /**
     * The name it answers now, from the setting's value when it names one,
     * or null when that value is empty or the filled name doesn't match NAME.
     *
     * @param  array<string, mixed>  $values  the plugin's settings, by key
     */
    public function filledName(array $values): ?string
    {
        $key = $this->nameSetting();
        $name = $key === null ? $this->name : self::text($values[$key] ?? null).'.txt';

        return preg_match(self::NAME, $name) === 1 ? $name : null;
    }

    /**
     * The body it answers now, from the setting's value when it names one,
     * or null when that is empty or longer than MOST_BYTES.
     *
     * @param  array<string, mixed>  $values  the plugin's settings, by key
     */
    public function filledBody(array $values): ?string
    {
        $key = $this->bodySetting();
        $body = $key === null ? $this->body : self::text($values[$key] ?? null);

        return $body === '' || strlen($body) > self::MOST_BYTES ? null : $body;
    }

    /**
     * The line the consent lists and keeps for it, as a public route's:
     * `GET /abcd1234.txt`, or, for a name filled from a setting,
     * `GET /{key}.txt (named by its setting “Label”)`. Kept as stored
     * consent, so it is never translated, as a route's line isn't.
     */
    public function consentLine(Manifest $manifest): string
    {
        $key = $this->nameSetting();

        if ($key === null) {
            return 'GET /'.$this->name;
        }

        $label = $key;

        foreach (is_array($manifest->contributes()['settings'] ?? null) ? $manifest->contributes()['settings'] : [] as $setting) {
            if (is_array($setting) && ($setting['key'] ?? null) === $key && is_string($setting['label'] ?? null)) {
                $label = $setting['label'];
            }
        }

        return 'GET /'.$this->name.' (named by its setting “'.$label.'”)';
    }

    private static function settingKey(string $value): ?string
    {
        return preg_match(self::FROM_SETTING, $value, $match) === 1 ? $match[1] : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
