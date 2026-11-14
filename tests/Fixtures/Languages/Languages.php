<?php

declare(strict_types=1);

namespace Tests\Fixtures\Languages;

use App\Domain\Cache\PublicChanges;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use Illuminate\Support\Facades\DB;

/**
 * Writes the site's other languages straight through SettingsWriter, as a
 * test that isn't about the Operations that change them needs (I-3360).
 */
final class Languages
{
    /**
     * Sets the other languages, in this order, with their prefixes (the
     * lower-case tag where none is given) and which are hidden.
     *
     * @param  list<string>  $others
     * @param  array<string, string>  $prefixes  tag => prefix
     * @param  list<string>  $hidden
     */
    public static function set(array $others, array $prefixes = [], array $hidden = []): void
    {
        $rows = array_map(fn (string $tag): array => ['name' => $tag, 'value' => $prefixes[$tag] ?? mb_strtolower($tag)], $others);

        self::write(new SettingsWrite(SiteLanguages::GROUP, [
            'others' => $others,
            'prefixes' => $rows,
            'hidden' => $hidden,
        ]));
    }

    /**
     * Sets the types and taxonomies that are not translated.
     *
     * @param  list<string>  $types
     * @param  list<string>  $taxonomies
     */
    public static function untranslated(array $types, array $taxonomies = []): void
    {
        self::write(new SettingsWrite(SiteLanguages::GROUP, [
            'untranslated_types' => $types,
            'untranslated_taxonomies' => $taxonomies,
        ]));
    }

    /**
     * Writes one group in a transaction, collecting the purge tags an
     * Operation would and dropping them: these tests read no cached page.
     */
    private static function write(SettingsWrite $write): void
    {
        $changes = app(PublicChanges::class);
        $changes->open();

        try {
            DB::transaction(fn (): int => app(SettingsWriter::class)->write($write));
        } finally {
            $changes->close();
        }
    }
}
