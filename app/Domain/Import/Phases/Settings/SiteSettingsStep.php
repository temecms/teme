<?php

/**
 * Once the items are here (I-342): the old site's title, tagline, feeds,
 * search engines' switch, Discussion, the comments module and its home
 * page, each group on its own and what a person changed since kept; and its
 * SEO plugin's sitemaps sent on to /sitemap.xml (I-1717). One job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Settings;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\ImportSettings;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Operations\Input\ImportSettingsInput;
use App\Domain\Import\Phases\Addresses\WordPressAddresses;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class SiteSettingsStep implements StepPhase
{
    /** The map row this step's settings are kept on. */
    public const string OBJECT = 'site-settings';

    public function __construct(private ImportMap $map, private SettingsReader $settings) {}

    public function key(): string
    {
        return 'settings';
    }

    public function order(): int
    {
        return 1000;
    }

    public function label(): string
    {
        return 'Settings';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'setting.', $context->actorId()));

        $answer = $reader->whole('settings');
        $options = is_array($answer['options'] ?? null) ? $answer['options'] : [];
        $pageType = WordPressAddresses::temeType(WordPressAddresses::PAGES);
        $later = WordPressSettings::later($options, WordPressAddresses::temeType(WordPressAddresses::POSTS), $pageType, $this->settings->stringList('discussion', 'open_for'), $this->tookComments($context->sourceId));
        $values = $later['values'];
        $messages = $later['messages'];
        [$home, $said] = $this->home($options, $context->sourceId, $pageType);
        $messages = [...$messages, ...$said];
        $kept = [];

        if ($home !== null) {
            $values['addresses'] = $home;
        }

        // Group by group: one Teme refuses leaves the rest written.
        foreach ($values as $group => $groupValues) {
            try {
                $written = app(ImportSettings::class)->execute(new ImportSettingsInput($context->sourceId, $context->runId, self::OBJECT, [$group => $groupValues], $context->actorId()));
                $kept = [...$kept, ...$written['kept']];
            } catch (ValidationException $refused) {
                $label = app(SettingsRegistry::class)->find($group)->label ?? $group;
                $reason = Str::limit((string) (Arr::first(Arr::flatten($refused->errors())) ?? ''), 600);
                $messages[] = new ImportMessage('setting.refused', "The old site’s {$label} settings couldn’t be copied: {$reason}", ['group' => $group]);
            }
        }

        if ($kept !== []) {
            $messages[] = new ImportMessage('setting.kept-here', 'Changed here since the last import, so kept as they are: '.WordPressSettings::labels($kept).'.', ['settings' => $kept]);
        }

        $plugin = is_string($answer['seo_plugin'] ?? null) ? $answer['seo_plugin'] : null;

        return StepResult::done([...$messages, ...$this->sitemaps($options, $plugin, $context)]);
    }

    /**
     * The home page and the posts page as the old site chose them, by the
     * pages the import made, with what didn't come said; null for the
     * values when its home page didn't come.
     *
     * @param  array<string, mixed>  $options
     *
     * @return array{0: array<string, string>|null, 1: list<ImportMessage>}
     */
    private function home(array $options, string $sourceId, ?string $pageType): array
    {
        if (self::text($options['show_on_front'] ?? '') !== WordPressSettings::FRONT_PAGE) {
            return [['front_shows' => AddressSettings::FRONT_LATEST, 'front_page_id' => '', 'posts_page_id' => ''], []];
        }

        $front = $this->page($sourceId, $options['page_on_front'] ?? 0, $pageType);

        if ($front === null) {
            return [null, [new ImportMessage('setting.home-page-missing', 'The old site’s home page was a page the import didn’t bring, so Teme’s home page stays as it was. Choose one in Settings › Reading.')]];
        }

        $wanted = is_numeric($options['page_for_posts'] ?? null) && (int) $options['page_for_posts'] > 0;
        $posts = $wanted ? $this->page($sourceId, $options['page_for_posts'], $pageType) : '';
        $said = $posts === null ? [new ImportMessage('setting.posts-page-missing', 'The old site listed its posts on a page the import didn’t bring, so Teme has no posts page. Choose one in Settings › Reading.')] : [];

        return [['front_shows' => AddressSettings::FRONT_PAGE, 'front_page_id' => $front, 'posts_page_id' => $posts ?? ''], $said];
    }

    /**
     * The published page the import made of WordPress's page $id, or null.
     */
    private function page(string $sourceId, mixed $id, ?string $pageType): ?string
    {
        $number = is_numeric($id) ? (int) $id : 0;

        if ($number < 1 || $pageType === null) {
            return null;
        }

        $item = $this->map->temeId($sourceId, SourceRef::of('post', $number));

        return $item !== null && DB::table('contents')->where('id', $item)->where('type', $pageType)->where('status', 'published')->whereNull('deleted_at')->exists() ? $item : null;
    }

    /**
     * Whether the import brought any comment, which switches comments on.
     */
    private function tookComments(string $sourceId): bool
    {
        return DB::table('import_map')->where('source_id', $sourceId)->where('object_type', 'comment')->whereNotNull('teme_id')->exists();
    }

    /**
     * The old SEO plugin's sitemaps, or WordPress's own, each a 301 to
     * /sitemap.xml; one Teme refuses, said. None for a site with plain
     * addresses, whose sitemaps had none of their own.
     *
     * @param  array<string, mixed>  $options
     *
     * @return list<ImportMessage>
     */
    private function sitemaps(array $options, ?string $plugin, ImportContext $context): array
    {
        if (self::text($options['permalink_structure'] ?? '') === '') {
            return [];
        }

        $messages = [];
        $taxonomies = [WordPressAddresses::CATEGORIES, WordPressAddresses::TAGS];

        foreach (SitemapAddresses::for($plugin, $this->counts($context->sourceId), $taxonomies) as $path) {
            try {
                app(ImportRedirect::class)->execute(new ImportRedirectInput(from: $path, to: '/sitemap.xml', actorId: $context->actorId()));
            } catch (ValidationException $refused) {
                $reason = Str::limit((string) (Arr::first(Arr::flatten($refused->errors())) ?? ''), 600);
                $messages[] = new ImportMessage('setting.sitemap-refused', "{$path}, one of the old site’s sitemaps, couldn’t be sent on to /sitemap.xml: {$reason}", ['address' => $path]);
            }
        }

        return $messages;
    }

    /**
     * How many published posts and pages, and categories and tags, the
     * import brought, by WordPress's names, and one page of authors.
     *
     * @return array<string, int>
     */
    private function counts(string $sourceId): array
    {
        $counts = [];

        foreach ([WordPressAddresses::POSTS, WordPressAddresses::PAGES] as $wordpress) {
            $type = WordPressAddresses::temeType($wordpress);

            if ($type !== null) {
                $counts[$wordpress] = DB::table('import_map')->join('contents', 'contents.id', '=', 'import_map.teme_id')
                    ->where('import_map.source_id', $sourceId)->where('import_map.object_type', 'post')
                    ->where('contents.type', $type)->where('contents.status', 'published')->count();
            }
        }

        foreach ([WordPressAddresses::CATEGORIES, WordPressAddresses::TAGS] as $wordpress) {
            $taxonomy = WordPressAddresses::temeTaxonomy($wordpress);

            if ($taxonomy !== null) {
                $counts[$wordpress] = DB::table('import_map')->join('terms', 'terms.id', '=', 'import_map.teme_id')
                    ->where('import_map.source_id', $sourceId)->where('import_map.object_type', 'term')
                    ->where('terms.taxonomy', $taxonomy->key)->count();
            }
        }

        $counts[SitemapAddresses::AUTHORS] = 1;

        return $counts;
    }

    /**
     * An option's value as trimmed text.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
