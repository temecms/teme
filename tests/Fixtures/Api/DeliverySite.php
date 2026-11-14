<?php

declare(strict_types=1);

namespace Tests\Fixtures\Api;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Models\Media;
use App\Domain\Regions\Operations\CreateRegion;
use App\Domain\Regions\Operations\Input\CreateRegionInput;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\CreateTaxonomy;
use App\Domain\Types\Operations\Input\CreateTaxonomyInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyDefinition;
use App\Domain\Types\TypeDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Fields\StoredFieldRows;

/**
 * A site holding an item in every state a reader can or can't see, for the
 * Delivery API's tests: every part's leak tests and the join run over it.
 * Each thing is made through the real Operations, as the site would make
 * it, on the clock of Africa/Lagos, with the default theme active.
 *
 * The stored type movie (excerpt and featured image on) uses the public
 * taxonomy genre and the stored taxonomy shelf, which isn't public. Its
 * fields, all shown after the article unless named: release_date (a date),
 * premiere (a date and time), runtime, rating (a choice), in_cinemas,
 * trailer_url, tagline, poster (a file) and sequel (an item); notes (text)
 * and backstage (a file) are hidden on the site.
 *
 * The items:
 * - public: "Arrival", a movie, published and public, by Ada Obi: every field
 *   set, featured image shell, filed under genre and shelf, its body linking
 *   to sequel, draft and private, a button card to draft and an image card of shell;
 * - sequel: "Arrival II", a movie, published and public;
 * - protected: "Locked", a movie, published, protected by the password
 *   hunter2, with an excerpt, the featured image protectedOnly, the body
 *   PROTECTED_WORDS and the shown tagline PROTECTED_TAGLINE;
 * - private: "Hidden", a movie, published then made private, featured privateOnly;
 * - draft: "Unfinished", a movie never published, featured draftOnly;
 * - newerDraft: a movie published as "Old title", then saved as a draft
 *   titled "SECRET NEW TITLE" with the body "SECRET WORDS";
 * - scheduled: "Tomorrow", a movie scheduled to publish in a week;
 * - trashed: "Binned", a movie published, then put in the trash;
 * - region: the default theme’s after-content region, published, linking to
 *   regionOnly (the region takes no image card);
 * - about: "About", a page, published and public, its social image socialOnly;
 * - ahead: "Coming", a movie published, then dated three days ahead;
 * - pointer: "Prequel", a movie, published and public, its sequel field
 *   naming draft;
 * - retired: "Old footage", published in the stored type documentary while
 *   it was public; the type's stored row has since been made not public;
 * and author2, a second author who has only a draft.
 *
 * The files, all images: shell (public's featured image and image card),
 * poster (public's poster), draftOnly, privateOnly, protectedOnly (each
 * used only as named above), hiddenOnly (only in public's hidden backstage),
 * regionOnly (only in the region) and socialOnly (only as about's social image).
 */
final readonly class DeliverySite
{
    public const string RELEASE = '01KA0DE1S1TE0000000000RD01';

    public const string PREMIERE = '01KA0DE1S1TE0000000000PM02';

    public const string RUNTIME = '01KA0DE1S1TE0000000000RT03';

    public const string RATING = '01KA0DE1S1TE0000000000RA04';

    public const string CINEMAS = '01KA0DE1S1TE0000000000CN05';

    public const string TRAILER = '01KA0DE1S1TE0000000000TR06';

    public const string TAGLINE = '01KA0DE1S1TE0000000000TG07';

    public const string POSTER = '01KA0DE1S1TE0000000000PS08';

    public const string SEQUEL = '01KA0DE1S1TE0000000000SQ09';

    public const string NOTES = '01KA0DE1S1TE0000000000NT10';

    public const string BACKSTAGE = '01KA0DE1S1TE0000000000BS11';

    /** The hidden notes field's value on public, which no answer may hold. */
    public const string NOTES_VALUE = 'Backstage notes for editors only';

    /** The protected item's password, which no answer may hold. */
    public const string PASSWORD = 'hunter2';

    /** The protected item's body, which no answer may hold. */
    public const string PROTECTED_WORDS = 'PROTECTED WORDS behind the password';

    /** The protected item's shown tagline, which no answer may hold. */
    public const string PROTECTED_TAGLINE = 'PROTECTED TAGLINE behind the password';

    private function __construct(
        public User $admin,
        public User $author,
        public User $author2,
        public Term $genre,
        public Term $shelf,
        public Media $shell,
        public Media $poster,
        public Media $draftOnly,
        public Media $privateOnly,
        public Media $protectedOnly,
        public Media $hiddenOnly,
        public Media $regionOnly,
        public Media $socialOnly,
        public Content $public,
        public Content $sequel,
        public Content $protected,
        public Content $private,
        public Content $draft,
        public Content $newerDraft,
        public Content $scheduled,
        public Content $trashed,
        public Content $region,
        public Content $about,
        public Content $ahead,
        public Content $pointer,
        public Content $retired,
    ) {}

    /**
     * Makes the site, as described above.
     */
    public static function build(): self
    {
        Storage::fake('media');
        Storage::fake('media-originals');
        config(['teme.theme_paths' => []]);
        themeActivate('default');

        $admin = ctAdmin();
        lscrTimezone('Africa/Lagos');
        siteSetLanguage($admin, 'en-GB');
        $author = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'bio' => 'Film editor.'])->assignRole('author');
        $author2 = User::factory()->create(['name' => 'Bo Lee'])->assignRole('author');

        self::movies($admin);
        $genre = app(CreateTerm::class)->execute(new SaveTermInput('genre', 'Science fiction', null, null, 'Films about what might be.', (string) $admin->id));
        $shelf = app(CreateTerm::class)->execute(new SaveTermInput('shelf', 'Top shelf', null, null, null, (string) $admin->id));

        $shell = mediaUpload($admin, mediaJpeg(2400, 1600), 'Shell.jpg');
        $poster = mediaUpload($admin, mediaJpeg(1000, 1500), 'Poster.jpg');
        $draftOnly = mediaUpload($admin, mediaJpeg(800, 600), 'Draft only.jpg');
        $privateOnly = mediaUpload($admin, mediaJpeg(801, 600), 'Private only.jpg');
        $protectedOnly = mediaUpload($admin, mediaJpeg(802, 600), 'Protected only.jpg');
        $hiddenOnly = mediaUpload($admin, mediaJpeg(803, 600), 'Hidden only.jpg');
        $regionOnly = mediaUpload($admin, mediaJpeg(804, 600), 'Region only.jpg');
        $socialOnly = mediaUpload($admin, mediaJpeg(1200, 630), 'Social only.jpg');

        $sequel = self::movie($admin, $author, 'Arrival II', [], publish: true);
        $draft = self::movie($admin, $author, 'Unfinished', [BuiltInFields::FEATURED_IMAGE => self::fileValue($draftOnly)], publish: false);
        $private = self::movie($admin, $author, 'Hidden', [BuiltInFields::FEATURED_IMAGE => self::fileValue($privateOnly)], publish: true);
        app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($private->id, 'private', null, (string) $admin->id));

        $public = self::movie($admin, $author, 'Arrival', [
            BuiltInFields::EXCERPT => 'A linguist is asked to talk to the visitors.',
            BuiltInFields::FEATURED_IMAGE => ['id' => $shell->id, 'alt' => 'Louise Banks at the shell', 'decorative' => false],
            self::RELEASE => '2016-11-11',
            self::PREMIERE => '2016-09-01T19:00:00+01:00',
            self::RUNTIME => 116,
            self::RATING => 'pg',
            self::CINEMAS => false,
            self::TRAILER => 'https://video.example/arrival',
            self::TAGLINE => 'Why are they here?',
            self::POSTER => ['id' => $poster->id, 'alt' => 'The poster', 'decorative' => false],
            self::SEQUEL => $sequel->id,
            self::NOTES => self::NOTES_VALUE,
            self::BACKSTAGE => self::fileValue($hiddenOnly),
        ], publish: true, document: self::arrivalBody($sequel, $draft, $private, $shell), terms: [$genre->id, $shelf->id]);

        $protected = self::movie($admin, $author, 'Locked', [
            BuiltInFields::EXCERPT => 'What the password keeps.',
            BuiltInFields::FEATURED_IMAGE => self::fileValue($protectedOnly),
            self::TAGLINE => self::PROTECTED_TAGLINE,
        ], publish: true, document: self::paragraphs(self::PROTECTED_WORDS));
        app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($protected->id, 'protected', self::PASSWORD, (string) $admin->id));

        $newerDraft = self::movie($admin, $author, 'Old title', [], publish: true);
        self::save($newerDraft, $author, 'SECRET NEW TITLE', [], self::paragraphs('SECRET WORDS'));

        $scheduled = self::movie($admin, $author, 'Tomorrow', [], publish: false);
        app(SchedulePublication::class)->execute(new SchedulePublicationInput($scheduled->id, (string) $scheduled->refresh()->working_revision_id, 'publish', now()->addWeek()->toIso8601String(), (string) $admin->id));

        $trashed = self::movie($admin, $author, 'Binned', [], publish: true);
        app(TrashContent::class)->execute(new ContentActionInput($trashed->id, (string) $admin->id));

        $region = app(CreateRegion::class)->execute(new CreateRegionInput('default', 'after-content', (string) $admin->id));
        self::save($region, $admin, (string) $region->refresh()->title, [], ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The region’s picture', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:media/'.$regionOnly->id]]]]]]]]);
        listsPublish($region, $admin);

        $about = lifecycleDraft($admin, 'About', 'page');
        self::save($about, $admin, 'About', [BuiltInFields::SOCIAL_IMAGE => self::fileValue($socialOnly)], self::paragraphs('Who we are.'));
        listsPublish($about, $admin);

        $ahead = self::movie($admin, $author, 'Coming', [], publish: true);
        app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($ahead->id, (string) $ahead->working_revision_id, (string) $admin->id, publishedAt: now()->addDays(3)->toIso8601String()));

        $pointer = self::movie($admin, $author, 'Prequel', [self::SEQUEL => $draft->id], publish: true);

        $retired = self::retired($admin, $author);

        lifecycleDraft($author2, 'Not yet', 'movie');

        return new self(
            $admin, $author, $author2, $genre, $shelf,
            $shell, $poster, $draftOnly, $privateOnly, $protectedOnly, $hiddenOnly, $regionOnly, $socialOnly,
            $public->refresh(), $sequel->refresh(), $protected->refresh(), $private->refresh(), $draft->refresh(), $newerDraft->refresh(),
            $scheduled->refresh(), $trashed->refresh(), $region->refresh(), $about->refresh(),
            $ahead->refresh(), $pointer->refresh(), $retired->refresh(),
        );
    }

    /**
     * The stored type movie with an excerpt and a featured image, its two
     * taxonomies and its fields.
     */
    private static function movies(User $admin): void
    {
        StoredFieldRows::movieType();

        app(UpdateType::class)->execute(new UpdateTypeInput(TypeDefinition::fromArray([
            'key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'icon' => 'film',
            'public' => true, 'archive' => true, 'searchable' => true,
            'taxonomies' => [], 'supports' => ['excerpt', 'featured_image'],
            'comments' => 'closed', 'pattern' => null,
        ]), (string) $admin->id));

        app(CreateTaxonomy::class)->execute(new CreateTaxonomyInput(TaxonomyDefinition::fromArray([
            'key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres', 'icon' => 'folder',
            'hierarchical' => true, 'public' => true, 'base' => 'genre', 'code_types' => [],
        ]), ['movie'], (string) $admin->id));

        app(CreateTaxonomy::class)->execute(new CreateTaxonomyInput(TaxonomyDefinition::fromArray([
            'key' => 'shelf', 'singular' => 'Shelf', 'plural' => 'Shelves', 'segment' => 'shelves', 'icon' => 'folder',
            'hierarchical' => false, 'public' => false, 'base' => null, 'code_types' => [],
        ]), ['movie'], (string) $admin->id));

        $fields = [
            [self::RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true]]],
            [self::PREMIERE, 'premiere', ['label' => 'Premiere', 'fieldType' => 'datetime']],
            [self::RUNTIME, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number']],
            [self::RATING, 'rating', ['label' => 'Rating', 'fieldType' => 'select', 'settings' => ['choices' => [['value' => 'pg', 'label' => 'PG-13'], ['value' => 'r', 'label' => 'R']]]]],
            [self::CINEMAS, 'in_cinemas', ['label' => 'In cinemas', 'fieldType' => 'boolean']],
            [self::TRAILER, 'trailer_url', ['label' => 'Trailer URL', 'fieldType' => 'url']],
            [self::TAGLINE, 'tagline', ['label' => 'Tagline']],
            [self::POSTER, 'poster', ['label' => 'Poster', 'fieldType' => 'media']],
            [self::SEQUEL, 'sequel', ['label' => 'Sequel', 'fieldType' => 'entry']],
            [self::NOTES, 'notes', ['label' => 'Notes', 'show' => 'hidden']],
            [self::BACKSTAGE, 'backstage', ['label' => 'Backstage', 'fieldType' => 'media', 'show' => 'hidden']],
        ];

        foreach ($fields as $position => [$key, $handle, $overrides]) {
            StoredFieldRows::store(StoredFieldRows::entry($key, $handle, $overrides), $position + 1);
        }
    }

    /**
     * An item published in the stored type documentary, which was public
     * then, and the type's stored row made not public after.
     */
    private static function retired(User $admin, User $author): Content
    {
        $documentary = ['key' => 'documentary', 'singular' => 'Documentary', 'plural' => 'Documentaries', 'segment' => 'documentaries', 'taxonomies' => [], 'supports' => [], 'comments' => null];
        ctCreate($admin, $documentary);

        $item = lifecycleDraft($author, 'Old footage', 'documentary');
        self::save($item, $author, 'Old footage', [], self::paragraphs('Once public.'));
        listsPublish($item, $admin);

        // UpdateType refuses to change a type with items (TypeRules), so the
        // stored row is written as another process's change would leave it,
        // as a restored older copy or a hand edit would.
        $definition = ctDefinition([...$documentary, 'public' => false, 'archive' => false, 'searchable' => false])->settled()->toArray();
        unset($definition['key']);
        DB::table(StoredTypes::TABLE)->where('key', 'documentary')->update(['definition' => json_encode($definition, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        app(StoredTypes::class)->remember();
        app(ContentTypeRegistry::class)->refreshStored();

        return $item->refresh();
    }

    /**
     * A movie by the author with these fields, terms and body, published by
     * the administrator when asked.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>|null  $document
     * @param  list<string>  $terms
     */
    private static function movie(User $admin, User $author, string $title, array $fields, bool $publish, ?array $document = null, array $terms = []): Content
    {
        $movie = lifecycleDraft($author, $title, 'movie');
        self::save($movie, $author, $title, $fields, $document ?? self::paragraphs("The story of {$title}."), $terms);

        if ($publish) {
            listsPublish($movie, $admin);
        }

        return $movie->refresh();
    }

    /**
     * Saves the item's working revision through SaveDraft.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $document
     * @param  list<string>  $terms
     */
    private static function save(Content $content, User $actor, string $title, array $fields, array $document, array $terms = []): void
    {
        $content->refresh();
        app(SaveDraft::class)->execute(new SaveDraftInput($content->id, $title, $document, $fields, $content->working_revision_id, (string) $actor->id, $terms));
    }

    /**
     * Arrival's body: links by ID to the sequel, a draft and a private item;
     * a button card to the draft; an image card of the shell.
     *
     * @return array<string, mixed>
     */
    private static function arrivalBody(Content $sequel, Content $draft, Content $private, Media $shell): array
    {
        $link = fn (string $text, Content $to): array => ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$to->id]]]];

        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Louise Banks teaches languages. '],
                $link('The sequel', $sequel),
                ['type' => 'text', 'text' => ' came later; '],
                $link('the unfinished cut', $draft),
                ['type' => 'text', 'text' => ' and '],
                $link('the hidden one', $private),
                ['type' => 'text', 'text' => ' did not.'],
            ]],
            ['type' => 'button', 'attrs' => ['id' => strtoupper((string) Str::ulid()), 'label' => 'Read the unfinished cut', 'contentId' => $draft->id]],
            self::imageCard($shell, 'The shell over the field'),
        ]];
    }

    /**
     * An image card of this file, with this use's alt text.
     *
     * @return array<string, mixed>
     */
    private static function imageCard(Media $media, string $alt): array
    {
        return ['type' => 'image', 'attrs' => ['id' => strtoupper((string) Str::ulid()), 'mediaId' => $media->id, 'alt' => $alt]];
    }

    /**
     * A body of one paragraph.
     *
     * @return array<string, mixed>
     */
    private static function paragraphs(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    /**
     * A file field's value, with no alt text of its own.
     *
     * @return array{id: string, alt: null, decorative: false}
     */
    private static function fileValue(Media $media): array
    {
        return ['id' => $media->id, 'alt' => null, 'decorative' => false];
    }
}
