<?php

/**
 * Teme's settings for a WordPress site's options (I-342): the time
 * zone and the language, which items are written with, so set first; then
 * the title, the feeds, discussion and the comments module. Pure, but for
 * the languages Teme offers and the settings' labels.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Settings;

use App\Domain\Feeds\FeedsSection;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Terms\WordPressText;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\Modules;
use App\Domain\Settings\SettingsRegistry;
use DateTimeZone;

final class WordPressSettings
{
    /** `show_on_front` when the home page is a page. */
    public const string FRONT_PAGE = 'page';

    /** `default_comment_status` when new posts take comments. */
    public const string OPEN = 'open';

    /**
     * Settings named `group.key`, in their own labels, for words: "Post
     * address, Time zone".
     *
     * @param  list<string>  $names
     */
    public static function labels(array $names): string
    {
        $registry = app(SettingsRegistry::class);

        return implode(', ', array_map(function (string $name) use ($registry): string {
            [$group, $key] = array_pad(explode('.', $name, 2), 2, '');

            return $registry->find($group)?->setting($key)->label ?? $name;
        }, $names));
    }

    /**
     * The general settings items are made with: the time zone, which dates
     * in addresses follow, and the language; with a warning for each Teme
     * can't take.
     *
     * @param  array<string, mixed>  $options
     *
     * @return array{values: array<string, string>, messages: list<ImportMessage>}
     */
    public static function early(array $options, string $locale): array
    {
        $values = [];
        $messages = [];
        $zone = trim(self::text($options['timezone_string'] ?? ''));
        $offset = (float) self::text($options['gmt_offset'] ?? '0');

        if ($zone !== '' && in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            $values['timezone'] = $zone;
        } elseif ($zone === '' && $offset === 0.0) {
            $values['timezone'] = 'UTC';
        } else {
            $was = $zone !== '' ? $zone : self::offset($offset);
            $messages[] = new ImportMessage('address.time-zone', "The old site’s time zone, {$was}, isn’t a place Teme can choose, so Teme kept its own. Check it in Settings › General: dates in addresses follow it.", ['was' => $was]);
        }

        $language = self::language($locale);

        if ($language !== null) {
            $values['language'] = $language;
        } elseif (trim($locale) !== '') {
            $was = trim($locale);
            $messages[] = new ImportMessage('address.language', "The old site’s language, {$was}, isn’t one Teme offers yet, so Teme kept its own. Check it in Settings › General.", ['was' => $was]);
        }

        return ['values' => $values, 'messages' => $messages];
    }

    /**
     * Teme's language for a WordPress locale (de_DE, pt_BR, de_DE_formal):
     * the locale itself, else its language alone; null when Teme offers neither.
     */
    private static function language(string $locale): ?string
    {
        $parts = explode('-', str_replace('_', '-', trim($locale)));
        $offered = (array) config('languages');
        $alone = strtolower($parts[0]);
        $tag = $alone.(isset($parts[1]) ? '-'.strtoupper($parts[1]) : '');

        if ($alone === '') {
            return null;
        }

        if (array_key_exists($tag, $offered)) {
            return $tag;
        }

        return array_key_exists($alone, $offered) ? $alone : null;
    }

    /**
     * A fixed offset as WordPress shows it: UTC+5:30.
     */
    private static function offset(float $hours): string
    {
        $minutes = (int) round(abs($hours) * 60);
        $rest = $minutes % 60;

        return 'UTC'.($hours < 0 ? '-' : '+').intdiv($minutes, 60).($rest === 0 ? '' : sprintf(':%02d', $rest));
    }

    /**
     * The settings the old site's options give once its items are here
     * (I-586): the title and tagline, posts per page, the feeds, search
     * engines, Discussion and the comments module, by group; what isn't kept
     * said. $openNow are the types open for comments here now; the posts'
     * type joins them when the old site opened posts, and the pages' never
     * does, as WordPress opens pages for nobody by default.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>  $openNow
     *
     * @return array{values: array<string, array<string, mixed>>, messages: list<ImportMessage>}
     */
    public static function later(array $options, ?string $postType, ?string $pageType, array $openNow, bool $tookComments): array
    {
        $on = fn (string $key): bool => self::text($options[$key] ?? '') === '1';
        $number = fn (string $key, int $default): int => is_numeric($options[$key] ?? null) ? (int) $options[$key] : $default;
        $values = ['general' => []];
        $messages = [];

        $title = mb_substr(WordPressText::decoded($options['blogname'] ?? ''), 0, 120);

        if ($title !== '') {
            $values['general']['site_title'] = $title;
        }

        $values['general']['tagline'] = mb_substr(WordPressText::decoded($options['blogdescription'] ?? ''), 0, 255);
        $perListing = $number('posts_per_page', 10);
        // WordPress's -1 is every post on one page; Teme's most is 100.
        $values['reading'] = ['posts_per_page' => $perListing < 0 ? 100 : max(1, min(100, $perListing))];
        $discourage = self::text($options['blog_public'] ?? '1') === '0';
        $values[SearchAndSocial::KEY] = [
            FeedsSection::ITEMS => max(1, min(100, abs($number('posts_per_rss', 10)))),
            FeedsSection::CONTENT => $on('rss_use_excerpt') ? FeedsSection::EXCERPT : FeedsSection::FULL,
            WholeSite::DISCOURAGE => $discourage,
        ];

        if ($discourage) {
            $messages[] = new ImportMessage('setting.discouraged', 'The old site asked search engines to stay away, so this one does too. Switch it off in Settings › Search and social once the site should be found.');
        }

        $open = self::text($options['default_comment_status'] ?? '') === self::OPEN;
        $others = array_values(array_diff($openNow, array_filter([$postType, $pageType])));
        $discussion = [
            'open_for' => $open && $postType !== null ? [...$others, $postType] : $others,
            'signed_in_only' => $on('comment_registration'),
            'close_after_days' => $on('close_comments_for_old_posts') ? max(0, min(3650, $number('close_comments_days_old', 14))) : 0,
            'thread_depth' => $on('thread_comments') ? max(1, min(10, $number('thread_comments_depth', 5))) : 1,
            'hold_all' => $on('comment_moderation'),
            'approve_returning' => $on('comment_previously_approved'),
            'hold_links' => max(0, min(100, $number('comment_max_links', 2))),
        ];
        $perPage = $number('comments_per_page', 0);

        // Only where WordPress paged them, so its comment-page-N addresses lead to the same comments here (I-610).
        if ($on('page_comments') && $perPage >= 1 && $perPage <= 200) {
            $discussion['per_page'] = $perPage;
        }

        $values['discussion'] = $discussion;

        if ($open || $tookComments) {
            $values['modules'] = [Modules::COMMENTS => Modules::ON];
        }

        $date = self::text($options['date_format'] ?? '');
        $time = self::text($options['time_format'] ?? '');
        $messages[] = new ImportMessage('setting.not-carried', "The old site’s date format ({$date}), time format ({$time}) and first day of the week aren’t kept: Teme writes dates as the site’s language does.", ['date' => $date, 'time' => $time]);

        return ['values' => $values, 'messages' => $messages];
    }

    /**
     * An option's value as text: WordPress gives strings, numbers or nothing.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
