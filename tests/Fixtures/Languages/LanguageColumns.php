<?php

declare(strict_types=1);

namespace Tests\Fixtures\Languages;

use App\Domain\Site\SiteLanguage;
use Illuminate\Support\Facades\DB;

/**
 * A check for the one rule every writer of a language keeps (I-3360): the
 * site's default language is stored as NULL, never as its tag.
 */
final class LanguageColumns
{
    /**
     * The IDs of the items and terms, trash included, that store the
     * default language's tag, items first.
     *
     * @return list<string>
     */
    public static function defaultTagged(): array
    {
        $tag = app(SiteLanguage::class)->tag();

        return [
            ...DB::table('contents')->where('language', $tag)->orderBy('id')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
            ...DB::table('terms')->where('language', $tag)->orderBy('id')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
        ];
    }
}
