<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Operations\ChangeAddresses;
use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Addresses\PathProjection;
use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiImage;
use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\Operations\Input\RecordAiActivityInput;
use App\Domain\Ai\Operations\RecordAiActivity;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\SignedInMarker;
use App\Domain\Cache\StaticPages;
use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\EditedComment;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\DeleteCommentPermanently;
use App\Domain\Comments\Operations\EditComment;
use App\Domain\Comments\Operations\Input\CommentActionInput;
use App\Domain\Comments\Operations\Input\EditCommentInput;
use App\Domain\Comments\Operations\Input\ModerateCommentInput;
use App\Domain\Comments\Operations\Input\ReplyToCommentInput;
use App\Domain\Comments\Operations\Input\SubmitCommentInput;
use App\Domain\Comments\Operations\ModerateComment;
use App\Domain\Comments\Operations\ReplyToComment;
use App\Domain\Comments\Operations\SubmitComment;
use App\Domain\Comments\PostedReply;
use App\Domain\Comments\Queries\CommentList;
use App\Domain\Comments\Queries\CommentListRequest;
use App\Domain\Comments\SubmittedComment;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\ApproveReview;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\CreatePreviewLink;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\CreatePreviewLinkInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\Input\SubmitForReviewInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\ReturnForChanges;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Contents\Operations\SubmitForReview;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Domain\Contents\SaveAutosaveResult;
use App\Domain\Dashboard\Queries\Dashboard;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\PublishDesign;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Design\ThemeSettings;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Hooks\OwnedHooks;
use App\Domain\Import\Convert\BlockConverter;
use App\Domain\Import\Convert\Conversion;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\KnownMedia;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\ConnectImportSource;
use App\Domain\Import\Operations\Input\ConnectImportSourceInput;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Operations\UploadMedia;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Menus\Operations\Input\SaveNavigationItemsInput;
use App\Domain\Menus\Operations\SaveNavigationItems;
use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavSection;
use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Preferences\PreferenceDefinition;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectProposal;
use App\Domain\Regions\Operations\CreateRegion;
use App\Domain\Regions\Operations\Input\CreateRegionInput;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Site\CommentViews;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Fragment;
use App\Domain\Site\Document\GraphFilter;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\MetaTag;
use App\Domain\Site\Document\Placement;
use App\Domain\Site\Document\PlacementContributor;
use App\Domain\Site\Document\Placements;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\SiteDates;
use App\Domain\Site\ViewModels\ListingItem;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\Operations\SaveSiteCode;
use App\Domain\Support\Network\HostResolver;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\DesignSettingsGroup;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\CreateTaxonomy;
use App\Domain\Types\Operations\CreateType;
use App\Domain\Types\Operations\Input\CreateTaxonomyInput;
use App\Domain\Types\Operations\Input\CreateTypeInput;
use App\Domain\Types\Operations\Input\UpdateTaxonomyInput;
use App\Domain\Types\Operations\UpdateTaxonomy;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyDefinition;
use App\Domain\Types\TypeDefinition;
use App\Domain\Updates\CheckForUpdates;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use App\Providers\TypesServiceProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ApiRequest;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Pest\Browser\Playwright\Playwright;
use PHPUnit\Framework\ExpectationFailedException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Media\MediaRef;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Search\SearchHit;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Ai\FakeAiProvider;
use Tests\Fixtures\Ai\FixtureTask;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FakeHostResolver;
use Tests\Fixtures\Import\NotesStep;
use Tests\Fixtures\Import\PeoplePhase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', 'Browser');

// The address check's clock, which a test can make leap (CheckClock): before any test, since PHP keeps the first lookup of a call.
require_once __DIR__.'/Fixtures/Import/check-clock.php';

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

// Content lifecycle helpers (slice 1.3a)

/**
 * A new draft, through the real Operation.
 */
function lifecycleDraft(User $author, string $title = 'A title', string $type = 'post'): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput(
        type: $type,
        title: $title,
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $author->id,
    ));
}

/**
 * A draft trashed the given number of days ago.
 */
function lifecycleTrashedDaysAgo(User $author, string $title, int $days): Content
{
    $content = lifecycleDraft($author, $title);
    test()->travel(-$days)->days();
    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $author->id));
    test()->travelBack();

    return $content;
}

/**
 * The counts computed from the rows themselves, to compare with the
 * maintained ones.
 *
 * @return array<string, int>
 */
function lifecycleTruth(string $type, int $authorId = 0): array
{
    return DB::table('contents')
        ->where('type', $type)
        ->when($authorId !== 0, fn ($query) => $query->where('author_id', $authorId))
        ->selectRaw("CASE WHEN deleted_at IS NOT NULL THEN 'trash' ELSE status END AS bucket, count(*) AS n")
        ->groupBy('bucket')
        ->orderBy('bucket')
        ->pluck('n', 'bucket')
        ->map(fn ($n): int => (int) $n)
        ->all();
}

/**
 * Media counts computed from the rows, to compare with the maintained ones.
 *
 * @return array<string, array<string, int>>
 */
function benchMediaTruth(): array
{
    $truth = [];

    foreach (DB::table('media')->selectRaw("kind, CASE WHEN deleted_at IS NULL THEN 'live' ELSE 'trash' END AS bucket, count(*) AS n")->groupBy('kind', 'bucket')->orderBy('kind')->orderBy('bucket')->get() as $row) {
        $truth[(string) $row->kind][(string) $row->bucket] = (int) $row->n;
    }

    return $truth;
}

// Taxonomy helpers (slice 1.3b)

/**
 * A category or tag, written directly — for tests that are not about the
 * term Operations themselves.
 */
function taxonomyTerm(string $taxonomy, string $name, ?string $parentId = null): Term
{
    return Term::query()->create([
        'taxonomy' => $taxonomy,
        'name' => $name,
        'slug' => Str::slug($name),
        'parent_id' => $parentId,
    ]);
}

// Content list helpers (slice 1.3c)

/**
 * Publishes an item's working revision as the given user.
 */
function listsPublish(Content $content, User $actor): void
{
    $content->refresh();

    app(PublishRevision::class)->execute(
        new PublishRevisionInput($content->id, $content->working_revision_id, (string) $actor->id),
    );
}

// Media helpers (slice 1.4a)

/**
 * A real JPEG of the given size, written to a temporary file.
 */
function mediaJpeg(int $width = 1200, int $height = 800): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 40));
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.jpg';
    imagejpeg($image, $path, 85);

    return $path;
}

/**
 * A minimal PDF, recognised by its content.
 */
function mediaPdf(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.pdf';
    file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    return $path;
}

/**
 * A GIF cut off after its first 40 bytes, as a damaged upload is: its
 * header still names a GIF of 40 × 30 pixels, but its image can't be
 * decoded.
 */
function mediaDamagedGif(): string
{
    $image = imagecreatetruecolor(40, 30);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 40));
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.gif';
    imagegif($image, $path);
    file_put_contents($path, substr((string) file_get_contents($path), 0, 40));

    return $path;
}

/**
 * An upload through the real Operation, as the given user.
 */
function mediaUpload(User $actor, string $path, string $name = 'Holiday.jpg', bool $allowDuplicate = false): Media
{
    return app(UploadMedia::class)->execute(new UploadMediaInput($path, $name, (string) $actor->id, $allowDuplicate));
}

// Media reference helpers (slice 1.4b)

/**
 * A new post draft with the given fields, through the real Operation.
 */
function refsDraft(User $author, array $fields): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Pictured', ['type' => 'doc', 'content' => []], $fields, (string) $author->id));
}

/**
 * The featured-image field set that uses this item, with no override.
 *
 * @return array<string, array{id: string, alt: null, decorative: false}>
 */
function refsFeatured(Media $media): array
{
    return [BuiltInFields::FEATURED_IMAGE => ['id' => $media->id, 'alt' => null, 'decorative' => false]];
}

/**
 * An item's usage rows, by scope.
 *
 * @return array<string, string> scope => media ID
 */
function refsRows(Content $content): array
{
    return DB::table('media_usages')->where('content_id', $content->id)->orderBy('scope')->pluck('media_id', 'scope')->all();
}

/**
 * A media item's maintained counters, [working, published].
 *
 * @return array{0: int, 1: int}
 */
function refsUses(Media $media): array
{
    $media->refresh();

    return [$media->working_uses, $media->published_uses];
}

/**
 * Saves a new working revision with these fields, through SaveDraft.
 */
function refsSave(Content $content, User $actor, array $fields): void
{
    $content->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput(
        $content->id, 'Pictured', ['type' => 'doc', 'content' => []], $fields, $content->working_revision_id, (string) $actor->id,
    ));
}

// Types helpers (slice 1.5·0)

/**
 * Binds fresh type and taxonomy registries holding only the core ones, so a
 * test can register its own without leaking into other tests.
 */
function typesFresh(): void
{
    TypesServiceProvider::bindCore(app());
}

/**
 * A draft of any type, through the real CreateDraft.
 *
 * @param  list<string>  $terms
 */
function typesDraft(User $author, string $type, string $title, array $terms = []): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput(
        $type, $title, ['type' => 'doc', 'content' => []], [], (string) $author->id, $terms,
    ));
}

/**
 * An editor, who may manage terms and create content, with the roles seeded.
 */
function typesEditor(): User
{
    test()->seed(RoleSeeder::class);

    return User::factory()->create()->assignRole('editor');
}

/**
 * A term, through the real CreateTerm, so its rules apply.
 */
function typesTerm(User $actor, string $taxonomy, string $name, ?string $parentId = null): Term
{
    return app(CreateTerm::class)->execute(new SaveTermInput($taxonomy, $name, null, $parentId, null, (string) $actor->id));
}

// Design system helpers (slice 1.5a)

/**
 * The source of one file under resources/js.
 */
function uiComponent(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * The admin stylesheet's source.
 */
function uiCss(): string
{
    return (string) file_get_contents(base_path('resources/css/app.css'));
}

/**
 * Every .tsx file under resources/js, as a path relative to it, so each can be
 * passed to uiComponent(). PHP's glob() does not recurse with **, so this
 * walks the directory.
 *
 * @return list<string>
 */
function uiTsxFiles(): array
{
    $root = resource_path('js');
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'tsx') {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($files);

    return $files;
}

/**
 * A minimal menu entry that links to the dashboard.
 */
function uiNavEntry(string $key, ?string $parent = null): NavEntry
{
    return new NavEntry($key, ucfirst($key), 'file', NavSection::Content, 100, route: 'dashboard', parent: $parent);
}

// Document helpers (slice 1.5b)

/**
 * A document holding the given top-level nodes.
 *
 * @return array<string, mixed>
 */
function docWith(array ...$nodes): array
{
    return ['type' => 'doc', 'content' => array_values($nodes)];
}

/**
 * A paragraph holding one run of plain text.
 *
 * @return array<string, mixed>
 */
function docText(string $text = 'Hello'): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

/**
 * Checks a document against the schema, throwing when it does not fit.
 */
function docValidate(array $document): void
{
    app(DocumentValidator::class)->validate($document);
}

/**
 * Saves a new working revision of the item, through the real Operation. The
 * fields and terms are the working revision's own unless given.
 *
 * @param  array<string, mixed>|null  $fields
 * @param  list<string>|null  $terms
 */
function docSave(Content $content, User $actor, array $document, ?array $fields = null, ?array $terms = null): Content
{
    $content->refresh();
    $current = ContentRevision::query()->findOrFail($content->working_revision_id);

    return app(SaveDraft::class)->execute(new SaveDraftInput(
        $content->id,
        $content->title,
        $document,
        $fields ?? (array) $current->fields,
        $content->working_revision_id,
        (string) $actor->id,
        $terms ?? (array) $current->terms,
    ));
}

// Editor helpers (slice 1.5c)

/**
 * Autosaves a document as the given person, based on the working revision
 * unless another is named. The title, fields and terms are the working
 * revision's own unless given.
 *
 * @param  array<string, mixed>|null  $fields
 * @param  list<string>|null  $terms
 */
function edSave(
    string $contentId,
    User $actor,
    array $document,
    ?string $basedOn = null,
    ?string $title = null,
    ?array $fields = null,
    ?array $terms = null,
): SaveAutosaveResult {
    $content = Content::query()->findOrFail($contentId);
    $current = ContentRevision::query()->findOrFail($content->working_revision_id);

    return app(SaveAutosave::class)->execute(new SaveAutosaveInput(
        $contentId,
        $title ?? $content->title,
        $document,
        $fields ?? (array) $current->fields,
        $terms ?? (array) $current->terms,
        $basedOn ?? $content->working_revision_id,
        (string) $actor->id,
    ));
}

/**
 * HTML with the whitespace between tags removed. Attribute order and
 * insignificant whitespace are not the point; the elements, their classes
 * and their text are.
 */
function edNormalise(string $html): string
{
    return trim(preg_replace('/>\s+</', '><', $html));
}

/**
 * The three images the image and gallery fixtures name, under those IDs and
 * with fixed variant paths, so the golden HTML does not depend on an upload's
 * random ID. A fresh upload has no uses yet, so re-keying it is safe.
 */
function edFixtureMedia(User $author): void
{
    foreach (['01K5Z9P2QH7N3X4C8V6B0M1T2A', '01K5Z9P2QH7N3X4C8V6B0M1T2B', '01K5Z9P2QH7N3X4C8V6B0M1T2C'] as $id) {
        $media = mediaUpload($author, mediaJpeg(1200, 800), 'harbour.jpg', allowDuplicate: true);
        $variants = [];

        foreach (['thumb' => 300, 'w640' => 640, 'w1280' => 1200] as $key => $width) {
            $variants[$key] = ['path' => "{$id}/v1/harbour-{$key}.webp", 'width' => $width, 'height' => intdiv($width * 2, 3), 'bytes' => 1000];
        }

        DB::table('media')->where('id', $media->id)->update([
            'id' => $id,
            'alt' => 'Boats in the harbour',
            'variants' => json_encode($variants),
        ]);
    }
}

// History helpers (slice 1.5d)

/**
 * A card node as it sits in a stored document. Not validated: DocumentDiff
 * compares documents, it does not check them.
 *
 * @param  array<string, mixed>  $attrs
 *
 * @return array<string, mixed>
 */
function histCard(string $type, array $attrs): array
{
    return ['type' => $type, 'attrs' => $attrs];
}

/**
 * What the editor's paste handling makes of one clipboard, run through Node:
 * `html` is cleanPastedHtml()'s answer and `embed` embedFromPastedText()'s,
 * each null when that half of the clipboard was not given. The providers are
 * the ones the editor screen sends. `site` is the site's address, for links
 * to the site itself. Skips the test when Node is missing.
 *
 * @param  array{html?: string, text?: string, site?: string}  $clipboard
 *
 * @return array{html: string|null, embed: array<string, mixed>|null}
 */
function histPaste(array $clipboard): array
{
    if (Process::run(['node', '--version'])->failed()) {
        test()->markTestSkipped('Node is not available here, so the editor\'s paste handling cannot be run. This is a known gap, not a pass.');
    }

    $input = tempnam(sys_get_temp_dir(), 'teme-paste');
    file_put_contents($input, json_encode([
        'html' => $clipboard['html'] ?? null,
        'text' => $clipboard['text'] ?? null,
        'site' => $clipboard['site'] ?? null,
        'providers' => CardManifest::forBrowser(app(CardRegistry::class))['embedProviders'],
    ]));

    $run = Process::timeout(60)->run(['node', base_path('tests/Fixtures/paste.mjs'), $input]);
    unlink($input);

    expect($run->successful())->toBeTrue($run->errorOutput());

    return json_decode($run->output(), true);
}

/**
 * HTML with the whitespace between tags removed, so an assertion reads the
 * structure rather than the line breaks.
 */
function histTight(?string $html): string
{
    return (string) preg_replace('/>\s+</', '><', (string) $html);
}

/**
 * Shares the content's working revision and returns the plain token as
 * `->token` and the link's ID as `->id`.
 */
function histPreview(Content $content, User $user): object
{
    $token = app(CreatePreviewLink::class)->execute(new CreatePreviewLinkInput(
        contentId: $content->id,
        revisionId: $content->working_revision_id,
        actorId: (string) $user->id,
    ));

    return (object) [
        'token' => $token,
        'id' => DB::table('preview_links')->where('token_hash', hash('sha256', $token))->value('id'),
    ];
}

// Publishing helpers (slice 1.5e)

/**
 * A test Operation that records when its hooks and invalidation run.
 *
 * @param  list<string>  $log
 */
function schedProbe(array &$log): BaseOperation
{
    return new class($log) extends BaseOperation
    {
        /**
         * @param  list<string>  $log
         */
        public function __construct(private array &$log) {}

        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            $this->log[] = 'perform';

            return null;
        }

        protected function afterCommit(mixed $result): void
        {
            $this->log[] = 'hooks';
        }

        protected function invalidateCache(mixed $result): void
        {
            $this->log[] = 'cache';
        }
    };
}

/**
 * Schedules an item's working revision, through the real Operation.
 */
function schedFor(Content $content, User $actor, string $when, string $action = 'publish'): mixed
{
    $content->refresh();

    return app(SchedulePublication::class)->execute(new SchedulePublicationInput(
        $content->id, $content->working_revision_id, $action, $when, (string) $actor->id,
    ));
}

/**
 * Schedules an item's working revision a minute out, travels past it, and
 * returns the schedule row's ID.
 */
function schedDue(Content $content, User $actor, string $action = 'publish'): string
{
    schedFor($content, $actor, now()->addMinute()->toIso8601String(), $action);
    test()->travel(2)->minutes();

    return (string) schedRow($content)->id;
}

/**
 * The item's most recent schedule row.
 */
function schedRow(Content $content): object
{
    return DB::table('scheduled_publications')
        ->where('content_id', $content->id)
        ->latest('created_at')->latest('id')
        ->first();
}

/**
 * Submits an item's working revision for review, and returns the review row's ID.
 */
function schedSubmit(Content $content, User $actor): string
{
    $content->refresh();

    app(SubmitForReview::class)->execute(new SubmitForReviewInput($content->id, $content->working_revision_id, (string) $actor->id));

    return (string) DB::table('content_reviews')
        ->where('content_id', $content->id)->whereNull('decided_at')->value('id');
}

/**
 * Approves a pending review, which publishes its pinned revision.
 */
function schedApprove(string $reviewId, User $actor): void
{
    app(ApproveReview::class)->execute(new ReviewDecisionInput($reviewId, (string) $actor->id));
}

/**
 * Returns a pending review to its writer with a note.
 */
function schedReturn(string $reviewId, User $actor, string $note = 'Needs a source for the second paragraph.'): void
{
    app(ReturnForChanges::class)->execute(new ReviewDecisionInput($reviewId, (string) $actor->id, $note));
}

/**
 * documentChecks() from the editor's rail, run in Node on one input of
 * { title, fields, document, media, linked }. Skips the test when Node is
 * missing.
 *
 * @param  array<string, mixed>  $input
 *
 * @return array<string, mixed>
 */
function schedChecks(array $input): array
{
    if (Process::run(['node', '--version'])->failed()) {
        test()->markTestSkipped('Node is not available here, so the rail\'s checks cannot be run. This is a known gap, not a pass.');
    }

    $file = tempnam(sys_get_temp_dir(), 'teme-checks');
    file_put_contents($file, json_encode($input));

    $run = Process::timeout(60)->run(['node', base_path('tests/Fixtures/checks.mjs'), $file]);
    unlink($file);

    expect($run->successful())->toBeTrue($run->errorOutput());

    return json_decode($run->output(), true);
}

/**
 * The editor's Template select for these declared templates and this
 * stored value, from the browser's own templateChoice().
 *
 * @param  list<array{slug: string, label: string}>  $declared
 *
 * @return array{options: list<array{value: string, label: string}>, missing: string|null}
 */
function themeTemplateChoice(array $declared, string $value): array
{
    if (Process::run(['node', '--version'])->failed()) {
        test()->markTestSkipped('Node is not available here, so the editor\'s template choice cannot be run. This is a known gap, not a pass.');
    }

    $file = tempnam(sys_get_temp_dir(), 'teme-template');
    file_put_contents($file, json_encode(['declared' => $declared, 'value' => $value]));

    $run = Process::timeout(60)->run(['node', base_path('tests/Fixtures/template-choice.mjs'), $file]);
    unlink($file);

    expect($run->successful())->toBeTrue($run->errorOutput());

    return json_decode($run->output(), true);
}

/**
 * A paragraph whose words link to one article, as the editor stores an
 * internal link (rule 9).
 *
 * @return array<string, mixed>
 */
function schedLinkTo(string $contentId, string $text): array
{
    return ['type' => 'paragraph', 'content' => [[
        'type' => 'text',
        'text' => $text,
        'marks' => [['type' => 'link', 'attrs' => ['href' => "teme:content/{$contentId}", 'target' => null, 'rel' => null, 'class' => null, 'title' => null]]],
    ]]];
}

/**
 * An input with no actor, for schedProbe().
 */
function schedProbeInput(): OperationInput
{
    return new class implements OperationInput
    {
        public function actorId(): ?string
        {
            return null;
        }
    };
}

// Command palette helpers (slice 1.5f)

/**
 * Every SQL statement one palette search runs, as the given person.
 *
 * @return list<string>
 */
function palQueries(User $actor, string $query): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($actor)->getJson('/admin/search?q='.urlencode($query))->assertOk();

    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $statements;
}

// Preference helpers (slice 1.5f)

/**
 * Declares a list preference on the app's registry, for the type no built-in
 * preference uses until 1.5h.
 */
function palListPreference(): void
{
    app(PreferenceRegistry::class)->register(new PreferenceDefinition(
        'pal.order',
        'list',
        [],
        label: 'Order',
        allowed: fn (): array => ['posts', 'pages', 'media'],
        maxItems: 3,
    ));
}

/**
 * The WCAG contrast ratio of two #rrggbb colours, from 1 to 21.
 */
function palContrast(string $one, string $two): float
{
    $luminance = function (string $hex): float {
        $channels = array_map(fn (string $pair): float => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
        $linear = array_map(fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };

    [$light, $dark] = [max($luminance($one), $luminance($two)), min($luminance($one), $luminance($two))];

    return ($light + 0.05) / ($dark + 0.05);
}

// Dashboard helpers (slice 1.5g)

/**
 * The keys of the panels the viewer's dashboard sends, in order.
 *
 * @return list<string>
 */
function dashKeys(User $viewer): array
{
    return array_column(app(Dashboard::class)->forUser($viewer)['panels'], 'key');
}

/**
 * One panel of the viewer's dashboard, or null when it is not sent.
 *
 * @return array{key: string, label: string, rows: list<array<string, string>>, seeAll: string|null}|null
 */
function dashPanel(User $viewer, string $key): ?array
{
    return collect(app(Dashboard::class)->forUser($viewer)['panels'])->firstWhere('key', $key);
}

/**
 * Every SQL statement the callback runs.
 *
 * @return list<string>
 */
function dashQueries(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $statements;
}

// List screen helpers (slice 1.5h)

/**
 * One page of the Posts list, as ContentList builds it.
 *
 * @param  array<string, mixed>  $arguments  ContentListRequest's named arguments
 *
 * @return array<string, mixed>
 */
function lscrPage(array $arguments): array
{
    return app(ContentList::class)->page(new ContentListRequest(...array_merge(['type' => 'post'], $arguments)));
}

/**
 * The EXPLAIN of the statement that fetches a list page's rows, with
 * sequential and bitmap scans turned off so a tiny test table still shows
 * whether an index can answer the order: a bitmap scan can't give one.
 *
 * @param  array<string, mixed>  $arguments  ContentListRequest's named arguments
 */
function lscrPlan(array $arguments): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    lscrPage($arguments);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $rows = collect($log)->first(fn (array $entry): bool => str_contains($entry['query'], 'order by "contents"'));

    return DB::transaction(function () use ($rows): string {
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET LOCAL enable_bitmapscan = off');

        return collect(DB::select('EXPLAIN '.$rows['query'], $rows['bindings']))->pluck('QUERY PLAN')->implode("\n");
    });
}

/**
 * Saves preferences for a person, through the real Operation, and reloads
 * them, so a later actingAs($person) reads what was saved.
 *
 * @param  array<string, mixed>  $preferences
 */
function lscrPrefer(User $person, array $preferences): void
{
    app(UpdatePreferences::class)->execute(new UpdatePreferencesInput($preferences, (string) $person->id));
    $person->refresh();
}

/**
 * Sets the site's time zone, as an administrator, through UpdateSettings.
 */
function lscrTimezone(string $zone): void
{
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new OperatorElevation);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['timezone' => $zone], [], [], 0, (string) $admin->id));
}

/**
 * Sends one Bulk Edit batch as the browser does: JSON, with each item's
 * title for the report, and the status left alone unless the changes say.
 *
 * @param  list<Content>  $items
 * @param  array<string, mixed>  $changes  add_terms, remove_terms, author_id, status
 */
function lscrBulkEdit(User $actor, array $items, array $changes): TestResponse
{
    return test()->actingAs($actor)->postJson(route('content.bulk', ['type' => 'posts']), [
        'action' => 'edit',
        'ids' => array_map(fn (Content $item): string => $item->id, $items),
        'titles' => collect($items)->mapWithKeys(fn (Content $item): array => [$item->id => $item->title])->all(),
        'status' => 'keep',
        ...$changes,
    ]);
}

// Address helpers (slice 1.6a)

/**
 * A published post with this title, through the real Operations.
 */
function addrPublished(User $author, string $title, string $type = 'post'): Content
{
    $content = lifecycleDraft($author, $title, $type);
    listsPublish($content, $author);

    return $content->refresh();
}

/**
 * The current address of a target, straight from the table.
 */
function addrCurrent(string $type, string $id): ?string
{
    return DB::table('paths')->where('target_type', $type)->where('target_id', $id)->where('is_current', true)->value('path');
}

/**
 * Changes only the item's slug, through the real Operation.
 */
function addrRename(Content $content, string $slug, User $actor): void
{
    $content->refresh();

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        contentId: $content->id,
        expectedRevisionId: $content->working_revision_id,
        actorId: (string) $actor->id,
        slug: $slug,
    ));
}

/**
 * Changes the address settings through the real Operation; unspecified values keep their defaults.
 *
 * @param  array<string, mixed>  $changes  patterns, bases, authorBase, frontShows, frontPageId, postsPageId
 *
 * @return array<string, mixed>
 */
function addrChange(User $actor, array $changes = []): array
{
    $values = array_replace_recursive([
        'patterns' => ['post' => '/{slug}/', 'page' => '/{slug}/'], 'bases' => ['category' => 'category', 'tag' => 'tag'],
        'authorBase' => 'author', 'frontShows' => 'latest', 'frontPageId' => '', 'postsPageId' => '',
    ], $changes);

    return app(ChangeAddresses::class)->execute(new ChangeAddressesInput(...array_values($values), actorId: (string) $actor->id));
}

/**
 * A GET of this exact path. The test client's own get() passes every URL
 * through the URL generator, which trims the trailing slash, so /about/ and
 * /about would be the same request.
 */
function addrGet(string $uri): TestResponse
{
    $request = Request::create($uri, 'GET');

    return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
}

/**
 * The Location header's path and query, exactly as sent.
 */
function addrLocation(TestResponse $response): string
{
    $location = (string) $response->headers->get('Location');
    $parts = parse_url($location);

    return ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
}

/**
 * Makes a theme the active one, as a test after boot needs it: discovery
 * sees the fixture themes in `config('teme.theme_paths')`, the theme-dependent
 * settings groups are built again from them, `design.theme` is written
 * straight to the settings table with its cache forgotten, and `theme::`
 * points at the theme with no view found earlier still cached.
 */
function themeActivate(string $code): void
{
    app(ThemeRegistry::class)->flush();
    DesignSettingsGroup::register(app(SettingsRegistry::class), app(ThemeRegistry::class));
    app(ThemeSettings::class)->register(app(ThemeRegistry::class));

    DB::table('settings')->updateOrInsert(['group_key' => 'design'], ['overrides' => json_encode(['theme' => $code])]);
    Cache::memo()->forget(SettingsRepository::cacheKey('design'));

    app(ActiveTheme::class)->forget();
}

/**
 * Sets the site language as an administrator, through UpdateSettings with
 * the real input and the group's current version.
 */
function siteSetLanguage(User $admin, string $tag): void
{
    $version = (int) DB::table('settings')->where('group_key', 'general')->value('version');

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['language' => $tag], [], [], $version, (string) $admin->id));
}

/**
 * A draft with this title and a one-paragraph body, through CreateDraft.
 */
function searchDraft(User $author, string $title, string $body, string $type = 'post'): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput(
        type: $type,
        title: $title,
        document: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]]]],
        fields: [],
        actorId: (string) $author->id,
    ));
}

/**
 * A published item with this title and body, through CreateDraft and PublishRevision.
 */
function searchPublished(User $author, string $title, string $body, string $type = 'post'): Content
{
    $content = searchDraft($author, $title, $body, $type);

    app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, $content->working_revision_id, (string) $author->id));

    return $content->refresh();
}

/**
 * A minimal valid manifest, with these keys replaced or added.
 *
 * @param  array<string, mixed>  $overrides
 *
 * @return array<string, mixed>
 */
function pageManifest(array $overrides): array
{
    return array_replace_recursive([
        'manifest' => 1, 'code' => 'good', 'name' => 'Good', 'version' => '0.1.0', 'description' => 'x',
        'requires' => ['teme' => '^0.1'], 'per_page' => 10,
        'images' => ['content' => '(min-width: 720px) 680px, 100vw'],
    ], $overrides);
}

/**
 * An uploaded image of this size, its variants made (the test queue is
 * synchronous), with its focal point set through UpdateMediaDetails when it
 * isn't the middle.
 */
function pageImage(User $author, int $w, int $h, int $focalX = 50, int $focalY = 50): Media
{
    $media = mediaUpload($author, mediaJpeg($w, $h), 'picture.jpg', allowDuplicate: true);

    if ($focalX !== 50 || $focalY !== 50) {
        app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, (string) $author->id, $media->title, $media->alt, $media->caption, $media->credit, $focalX, $focalY));
    }

    return $media->refresh();
}

/**
 * A published article of $words words, with these fields and terms: created
 * through CreateDraft, saved through SaveDraft, published with listsPublish.
 *
 * @param  list<Term>  $categories
 * @param  list<Term>  $tags
 */
function pageArticle(User $author, string $title, int $words = 50, ?string $excerpt = null, array $categories = [], array $tags = [], ?Media $featured = null, bool $sticky = false): Content
{
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => trim(str_repeat('word ', max(1, $words)))]]];
    $fields = array_filter([
        BuiltInFields::EXCERPT => $excerpt,
        BuiltInFields::STICKY => $sticky ? true : null,
    ], fn (mixed $value): bool => $value !== null);

    return pagePublish($author, $title, [$paragraph], $fields, [...array_map(fn (Term $term): string => $term->id, $categories), ...array_map(fn (Term $term): string => $term->id, $tags)], $featured);
}

/**
 * A published article made of exactly these top-level nodes.
 *
 * @param  list<array<string, mixed>>  $nodes
 */
function pageArticleFromDocument(User $author, string $title, array $nodes, ?Media $featured = null): Content
{
    return pagePublish($author, $title, $nodes, [], [], $featured);
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @param  array<string, mixed>  $fields
 * @param  list<string>  $terms
 */
function pagePublish(User $author, string $title, array $nodes, array $fields, array $terms, ?Media $featured): Content
{
    $content = lifecycleDraft($author, $title);

    if ($featured !== null) {
        $fields[BuiltInFields::FEATURED_IMAGE] = ['id' => $featured->id, 'alt' => null, 'decorative' => false];
    }

    app(SaveDraft::class)->execute(new SaveDraftInput($content->id, $title, ['type' => 'doc', 'content' => $nodes], $fields, $content->working_revision_id, (string) $author->id, $terms));
    listsPublish($content, $author);

    return $content->refresh();
}

/**
 * A new working revision with this title, as the item's author, keeping the
 * working revision's document, fields and terms, and not published.
 */
function pageSaveDraft(Content $content, string $title): void
{
    $content->refresh();
    $current = ContentRevision::query()->findOrFail($content->working_revision_id);

    app(SaveDraft::class)->execute(new SaveDraftInput(
        $content->id, $title, (array) $current->document, (array) $current->fields,
        $content->working_revision_id, (string) $content->author_id, (array) $current->terms,
    ));
}

/**
 * An image card node in 1.5b's shape (tests/Fixtures/Documents/image.json),
 * for this media item, with this node ID: a ULID, as ImageCard requires.
 *
 * @return array<string, mixed>
 */
function pageImageNode(Media $media, string $nodeId): array
{
    return ['type' => 'image', 'attrs' => [
        'id' => $nodeId, 'mediaId' => $media->id, 'alt' => null, 'decorative' => false,
        'caption' => null, 'credit' => null, 'width' => 'column',
    ]];
}

/**
 * Trashes the item through TrashContent's real input.
 */
function pageTrash(Content $content, User $actor): void
{
    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $actor->id));
}

/**
 * The site's name, from 1.2's real setting.
 */
function pageSiteName(): string
{
    return app(SettingsReader::class)->string('general', 'site_title');
}

/**
 * A published article made of these cards, each node copied from 1.5b's
 * fixture for that card (tests/Fixtures/Documents/{card}.json).
 *
 * @param  list<string>  $cards
 */
function pageArticleWithCards(User $author, string $title, array $cards): Content
{
    return pageArticleFromDocument($author, $title, array_map(regionCardNode(...), $cards));
}

// Editor refusals helpers (I-452, I-453)

/**
 * The JSON answer of one of the editor's Node fixtures (tests/Fixtures) to
 * one JSON input. Skips the test when Node is missing, as histPaste() does.
 *
 * @param  array<string, mixed>  $input
 *
 * @return array<mixed>
 */
function guardNode(string $script, array $input): array
{
    if (Process::run(['node', '--version'])->failed()) {
        test()->markTestSkipped('Node is not available here, so the editor cannot be run. This is a known gap, not a pass.');
    }

    $file = tempnam(sys_get_temp_dir(), 'teme-guard');
    file_put_contents($file, json_encode($input));

    $run = Process::timeout(120)->run(['node', base_path("tests/Fixtures/{$script}"), $file]);
    unlink($file);

    expect($run->successful())->toBeTrue($run->errorOutput());

    return json_decode($run->output(), true);
}

/**
 * What the editor's own schema can make, from tests/Fixtures/editor-schema.mjs:
 * each node and mark with the attributes it declares, the documents it can
 * make by name, and each card before and after a trip through the clipboard.
 * Run once per test process.
 *
 * @return array{nodes: array<string, list<string>>, marks: array<string, list<string>>, documents: array<string, array<string, mixed>>, cards: array<string, array{before: array<string, mixed>, after: array<string, mixed>}>}
 */
function guardEditor(): array
{
    static $editor = null;

    return $editor ??= guardNode('editor-schema.mjs', [
        'manifest' => CardManifest::forBrowser(app(CardRegistry::class)),
    ]);
}

/**
 * A paragraph whose words link to this address, as a paste leaves it.
 *
 * @return array<string, mixed>
 */
function guardLink(string $href, string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]];
}

/**
 * The answers of one exported function of an editor module, run in Node
 * through tests/Fixtures/editor-call.mjs: one answer for each list of
 * arguments.
 *
 * @param  list<list<mixed>>  $argumentLists
 *
 * @return list<mixed>
 */
function guardCall(string $module, string $function, array $argumentLists): array
{
    return guardNode('editor-call.mjs', [
        'module' => $module,
        'calls' => array_map(fn (array $arguments): array => ['name' => $function, 'args' => $arguments], $argumentLists),
    ]);
}

/**
 * A document after the editor applies an autosave's link changes, run in
 * Node through tests/Fixtures/editor-change.mjs, and whether it changed.
 *
 * @param  array<string, mixed>  $document
 * @param  list<array{from: string, to: string|null}>  $links
 *
 * @return array{changed: bool, document: array<string, mixed>}
 */
function guardChange(array $document, array $links): array
{
    return guardNode('editor-change.mjs', [
        'manifest' => CardManifest::forBrowser(app(CardRegistry::class)),
        'document' => $document,
        'links' => $links,
    ]);
}

// Navigation helpers (slice 1.6d)

/**
 * A new menu with this name, through CreateNavigation.
 */
function navCreate(User $actor, string $name = 'Main'): Navigation
{
    return app(CreateNavigation::class)->execute(new CreateNavigationInput($name, (string) $actor->id));
}

/**
 * Replaces the menu's items with this tree, through SaveNavigationItems.
 *
 * @param  list<array<string, mixed>>  $tree
 */
function navSave(User $actor, Navigation $nav, array $tree): void
{
    app(SaveNavigationItems::class)->execute(new SaveNavigationItemsInput($nav->id, $tree, (string) $actor->id));
}

// Region helpers (slice 1.6e)

/**
 * The theme's region's content row, made through CreateRegion.
 */
function regionCreate(User $actor, string $theme = 'good', string $region = 'after-content'): Content
{
    return app(CreateRegion::class)->execute(new CreateRegionInput($theme, $region, (string) $actor->id));
}

/**
 * Saves the region's document, made of these nodes, through SaveDraft.
 *
 * @param  list<array<string, mixed>>  $nodes
 */
function regionSave(Content $region, User $actor, array $nodes): void
{
    docSave($region, $actor, docWith(...$nodes));
}

/**
 * One card's node, copied from 1.5b's fixture for it
 * (tests/Fixtures/Documents/{card}.json), with a fresh ID.
 *
 * @return array<string, mixed>
 */
function regionCardNode(string $card): array
{
    $path = base_path("tests/Fixtures/Documents/{$card}.json");
    expect($path)->toBeFile("1.5b has no fixture for the {$card} card.");

    $node = collect(json_decode((string) file_get_contents($path), true)['content'])->firstWhere('type', $card);
    expect($node)->not->toBeNull("The {$card} fixture holds no {$card} node.");

    $node['attrs']['id'] = (string) Str::ulid();

    return $node;
}

// Design helpers (slice 1.6f)

/**
 * The good fixture's manifest with its `settings` replaced.
 *
 * @param  list<array<string, mixed>>  $settings
 *
 * @return array<string, mixed>
 */
function designManifest(array $settings): array
{
    $manifest = json_decode((string) file_get_contents(base_path('tests/Fixtures/themes/good/teme-theme.json')), true, 512, JSON_THROW_ON_ERROR);

    return ['settings' => $settings] + $manifest;
}

// Default theme helpers (slice 1.6g)

/**
 * A page's HTML as XPath, with libxml's complaints about HTML5 kept quiet.
 */
function dtDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/**
 * A publication's worth of site, made through the real Operations as this
 * editor: the categories News (with a description) and Features, the tags
 * Herons, Tides and Ferries; seventeen posts, one about a lifeboat for
 * search, three with featured images and two pinned, the newer of them,
 * pinnedNewest, the lead; longRead, 1,200
 * words in News with a written excerpt, a featured image and two tags; the
 * page About; a primary and a footer menu in the default theme's places;
 * the after-content region with a callout; the author's bio, which gives
 * them their address; and, on the long read, a thread over two pages of
 * comments, two conversations a page: Ada Guest's imported comment with
 * its WordPress number and website, and the author's reply to it; a ping;
 * Bo Reader's comment; and a tombstone with a reply.
 *
 * @return array{news: Term, features: Term, herons: Term, tides: Term, ferries: Term, about: Content, pinnedNewest: Content, longRead: Content, author: User, commentsPage: string, replyPage: string}
 */
function dtSite(User $author): array
{
    $actor = (string) $author->id;
    $term = fn (string $taxonomy, string $name, ?string $description = null): Term => app(CreateTerm::class)->execute(new SaveTermInput($taxonomy, $name, null, null, $description, $actor));

    $news = $term('category', 'News', 'Stories from the harbour and the city.');
    $features = $term('category', 'Features');
    $herons = $term('tag', 'Herons');
    $tides = $term('tag', 'Tides');
    $ferries = $term('tag', 'Ferries');
    $tags = [$herons, $tides, $ferries];

    // Published first, so the pinned post published after it leads.
    pageArticle($author, 'Tide tables for the week', excerpt: 'When the water turns, day by day.', categories: [$news], tags: [$tides], sticky: true);

    foreach (range(1, 13) as $n) {
        pageArticle(
            $author,
            "Harbour note {$n}",
            excerpt: $n % 4 === 0 ? null : "A short note from the harbour, number {$n}.",
            categories: [$n % 2 === 0 ? $features : $news],
            tags: [$tags[$n % 3]],
            featured: $n === 7 ? pageImage($author, 1200, 800) : null,
        );
    }

    // Found by the search page's tests and the floor's search row (1.6h).
    searchPublished($author, 'The lifeboat crew', 'The lifeboat crew launched at dawn from the harbour wall.');

    $pinnedNewest = pageArticle($author, 'Herons return to the creek', excerpt: 'After three years away, the colony is back.', categories: [$news], tags: [$herons], featured: pageImage($author, 1600, 900), sticky: true);
    $longRead = pageArticle($author, 'A year on the harbour', words: 1200, excerpt: 'Twelve months of tides, ferries and herons, from the quay.', categories: [$news], tags: [$herons, $tides], featured: pageImage($author, 1600, 900));

    // A thread on the long read, over two pages (1.8b).
    cmSettings(['per_page' => 2]);
    $imported = cmComment($longRead, attributes: ['author_name' => 'Ada Guest', 'author_url' => 'https://ada.example', 'legacy_id' => 123]);
    cmComment($longRead, parent: $imported, attributes: ['author_name' => $author->name, 'author_email' => $author->email, 'author_user_id' => $author->id]);
    cmComment($longRead, attributes: ['kind' => 'pingback', 'author_name' => 'Tide Watch', 'author_url' => 'https://tides.example/ferries', 'author_email' => null]);
    cmComment($longRead, attributes: ['author_name' => 'Bo Reader']);
    $gone = cmComment($longRead, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    cmComment($longRead, parent: $gone, attributes: ['author_name' => 'Cy Reader']);

    $about = addrPublished($author, 'About', 'page');

    $primary = navCreate($author, 'Primary');
    navSave($author, $primary, [['kind' => 'home'], ['kind' => 'content', 'target_id' => $about->id], ['kind' => 'term', 'target_id' => $news->id]]);
    $footer = navCreate($author, 'Footer');
    navSave($author, $footer, [['kind' => 'content', 'target_id' => $about->id]]);

    foreach (['primary' => $primary, 'footer' => $footer] as $slot => $menu) {
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, $actor));
    }

    $region = regionCreate($author, 'default');
    regionSave($region, $author, [regionCardNode('callout')]);
    listsPublish($region, $author);

    app(UpdateProfile::class)->execute(new UpdateProfileInput($actor, $author->name, $author->email, $actor, 'Jane writes about the harbour and its herons.'));

    return [
        'news' => $news->refresh(),
        'features' => $features,
        'herons' => $herons,
        'tides' => $tides,
        'ferries' => $ferries,
        'about' => $about,
        'pinnedNewest' => $pinnedNewest,
        'longRead' => $longRead,
        'commentsPage' => CommentViews::pageUrl((string) Addresses::relativeUrl($longRead), 2),
        'replyPage' => '/_teme/comment/'.$imported->id.'/reply',
        'author' => $author->refresh(),
    ];
}

/**
 * Publishes these design values as an administrator, through 1.6f's
 * SaveDesignDraft and PublishDesign, never the settings table, so the
 * design cache is forgotten as in production. Groups are keyed as 1.6f
 * registers them: `design`, and `theme.{code}` for a theme's own.
 *
 * @param  array<string, array<string, mixed>>  $groups
 */
function designPublish(array $groups): void
{
    $admin = (string) User::factory()->create()->assignRole('administrator')->id;

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput($groups, $admin));
    app(PublishDesign::class)->execute(new PublishDesignInput($admin));
}

/**
 * A real address from dtSite's fixture for a name the floor test uses: an
 * item, term or author by its address, with the trailing slash its pattern
 * gives; `year` as this year's archive on the site's clock; a path as it is.
 *
 * @param  array<string, mixed>  $site
 */
function dtUrl(array $site, string $what): string
{
    if (str_starts_with($what, '/')) {
        return $what;
    }

    if ($what === 'year') {
        return Addresses::toUrl((string) SiteDates::inSite('now')->year);
    }

    if (is_string($site[$what] ?? null)) {
        return $site[$what];
    }

    return Addresses::relativeUrl($site[$what]) ?? throw new RuntimeException("The fixture's {$what} has no address.");
}

/**
 * Whether the page's headings outside `.prose`, a writer's own, never skip
 * a level on the way down.
 */
function dtHeadingsInOrder(DOMXPath $dom): bool
{
    $previous = 0;
    $headings = $dom->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " prose ")])]');

    foreach ($headings === false ? [] : $headings as $heading) {
        $level = (int) substr($heading->nodeName, 1);

        if ($previous !== 0 && $level > $previous + 1) {
            return false;
        }

        $previous = $level;
    }

    return true;
}

// Site search helpers (slice 1.6h)

/**
 * The content IDs a site search finds for this query, in the order found.
 *
 * @return list<string>
 */
function searchIds(string $query, int $page = 1, int $perPage = 10): array
{
    $hits = app(SiteSearch::class)->search(new SearchRequest($query, $page, $perPage));

    return array_map(fn (SearchHit $hit): string => $hit->contentId, $hits->hits);
}

/**
 * The default theme's after-content region, published with this text as
 * its one paragraph: indexed like any published item, and kept out of search
 * only by its type.
 */
function searchRegion(User $author, string $text): void
{
    $region = regionCreate($author, 'default');
    regionSave($region, $author, [docText($text)]);
    listsPublish($region, $author);
}

/**
 * Every template the default theme draws, by a name dtUrl() turns into an
 * address: the floor test and the browser's axe check both read it.
 */
dataset('templates', fn () => [
    'front' => '/', 'page two' => '/page/2/', 'article' => 'longRead', 'page' => '/about/',
    'category' => 'news', 'tag' => 'herons', 'author' => 'author', 'date' => 'year', 'not found' => '/nowhere/',
    'search' => '/?s=lifeboat', 'comments page' => 'commentsPage', 'reply page' => 'replyPage',
]);

// Query plan helpers (slice 1.6h, from its benchmark)

/**
 * The statements $run makes, each with its bindings, in order.
 *
 * @return list<array{query: string, bindings: array<int, mixed>, time: float}>
 */
function planStatements(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $run();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
    }

    return $log;
}

/**
 * The EXPLAIN of the first statement $run makes that contains $needle, with
 * sequential scans turned off so a tiny test table still shows whether an
 * index can answer it, as lscrPlan() does for the list screens.
 */
function planOf(Closure $run, string $needle): string
{
    $statement = collect(planStatements($run))->first(fn (array $entry): bool => str_contains($entry['query'], $needle));

    expect($statement)->not->toBeNull("No statement contains {$needle}");

    return DB::transaction(function () use ($statement): string {
        DB::statement('SET LOCAL enable_seqscan = off');

        return collect(DB::select('EXPLAIN '.$statement['query'], $statement['bindings']))->pluck('QUERY PLAN')->implode("\n");
    });
}

/**
 * Published posts written straight into contents, with no revision, only so
 * a plan's choices look as they do on a large site: listings and articles
 * join the published revision, so they skip them. With $text, each is
 * searchable for it. Each call numbers on from the last; the table is
 * analyzed after.
 */
function planFillerPosts(User $author, int $count = 2000, ?string $text = null): void
{
    DB::statement(
        'INSERT INTO contents (id, type, slug, status, author_id, published_at, fields, title, search_tsv) '
        ."SELECT 'f' || lpad((n.done + g)::text, 25, '0'), 'post', 'filler-' || (n.done + g), 'published', ?, now() - interval '1 day' - (n.done + g) * interval '1 minute', '{}', 'Filler ' || (n.done + g), "
        ."CASE WHEN ?::text IS NULL THEN NULL ELSE to_tsvector('english', ?::text) END "
        ."FROM generate_series(1, ?) g, (SELECT count(*) AS done FROM contents WHERE id LIKE 'f%') n",
        [$author->id, $text, $text, $count],
    );
    DB::statement('ANALYZE contents');
}

/**
 * As planOf(), but the plan PostgreSQL makes without the bindings' values,
 * a generic plan, as a prepared statement run many times gets: a partial
 * index is used there only if the statement writes the index's condition
 * itself.
 */
function genericPlanOf(Closure $run, string $needle): string
{
    $statement = collect(planStatements($run))->first(fn (array $entry): bool => str_contains($entry['query'], $needle));

    expect($statement)->not->toBeNull("No statement contains {$needle}");

    $number = 0;
    $sql = (string) preg_replace_callback('/\?/', function () use (&$number): string {
        $number++;

        return '$'.$number;
    }, $statement['query']);
    $values = array_map(
        fn (mixed $value): string => $value === null ? 'NULL' : DB::getPdo()->quote((string) $value),
        DB::connection()->prepareBindings($statement['bindings']),
    );

    return DB::transaction(function () use ($sql, $values): string {
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET LOCAL plan_cache_mode = force_generic_plan');
        DB::unprepared('PREPARE teme_generic_plan AS '.$sql);

        try {
            return collect(DB::select('EXPLAIN EXECUTE teme_generic_plan('.implode(', ', $values).')'))->pluck('QUERY PLAN')->implode("\n");
        } finally {
            DB::unprepared('DEALLOCATE teme_generic_plan');
        }
    });
}

/**
 * Whether $plan reads $relation (a table and its alias as EXPLAIN writes
 * them, such as "paths p") through an index whose name matches $index, a
 * regular expression's fragment: by an index scan or an index-only scan,
 * either way along it, or by a bitmap scan of that index under a bitmap
 * heap scan of the relation. On a small table which of them the planner
 * picks varies with its statistics and the load; each reads the index,
 * never the whole table. An order an index gives needs the first two.
 */
function planReadsIndex(string $plan, string $index, string $relation): bool
{
    $on = preg_quote($relation, '/');

    return preg_match("/Index (Only )?Scan (Backward )?using {$index} on {$on}\\b/", $plan) === 1
        || (preg_match("/Bitmap Heap Scan on {$on}\\b/", $plan) === 1 && preg_match("/Bitmap Index Scan on {$index}\\b/", $plan) === 1);
}

// Page cache helpers (slice 1.7a)

/**
 * Turns the page cache on for one test: its files in a folder of this
 * process's own, since the parallel gate runs twelve at once, the site at
 * the host the test client asks for, since only APP_URL's host is cached,
 * and a page's life at a minute, the one 1.7a's and 1.7b's tests were
 * written against, so none straddles a new year (1.7c): a test of a
 * longer life sets its own. Returns the folder, for cacheOff().
 */
function cacheOn(): string
{
    $folder = sys_get_temp_dir().'/teme-page-cache-'.getmypid().'-'.uniqid();

    config([
        'page_cache.enabled' => true,
        'page_cache.lifetime' => 60,
        'page_cache.driver' => 'file',
        'page_cache.stores.file.path' => $folder,
        'app.url' => 'http://localhost',
    ]);

    return $folder;
}

/**
 * Removes the folder cacheOn() made; its rows go with the test's
 * transaction. Tests may list a folder; app/ never does.
 */
function cacheOff(string $folder): void
{
    (new Filesystem)->deleteDirectory($folder);
}

/**
 * The page the page cache holds for this address, expired or not, or null.
 */
function cacheEntry(string $uri): ?PageEntry
{
    $key = PageKey::of(Request::create($uri)) ?? throw new LogicException("No page is stored for {$uri}.");

    return app(PageStore::class)->get($key);
}

/**
 * A page put straight into the page store for this address, with this
 * body, life and tags; the address's own tag when none are given.
 *
 * @param  list<string>|null  $tags
 */
function cachePage(string $uri, string $body = '<p>Stored</p>', int $lifetime = 60, ?array $tags = null): PageEntry
{
    $key = PageKey::of(Request::create($uri)) ?? throw new LogicException("No page is stored for {$uri}.");
    $now = now()->getTimestamp();
    $entry = new PageEntry($key->url, 200, ['Content-Type' => ['text/html; charset=UTF-8']], $body, $now, $now + $lifetime, $tags ?? [$key->urlTag()]);

    app(PageStore::class)->put($entry);

    return $entry;
}

/**
 * A request for this exact path, as addrGet() makes one, with these
 * cookies and this method.
 *
 * @param  array<string, string>  $cookies
 */
function cacheGet(string $uri, array $cookies = [], string $method = 'GET'): TestResponse
{
    $request = Request::create($uri, $method, cookies: $cookies);

    return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
}

// Page cache purge helpers (slice 1.7b)

/**
 * Pages put straight into the page store, each at its address with these
 * tags, for a purge test: address => tags.
 *
 * @param  array<string, list<string>>  $pages
 */
function cacheShown(array $pages): void
{
    foreach ($pages as $uri => $tags) {
        cachePage($uri, tags: $tags);
    }
}

/**
 * Which of these addresses still have a stored page, in the order given.
 *
 * @return list<string>
 */
function cacheKept(string ...$uris): array
{
    return array_values(array_filter($uris, fn (string $uri): bool => cacheEntry($uri) !== null));
}

/**
 * Runs the change inside a transaction that then rolls back, as a failure
 * after it would: whatever it would purge must stay stored (rule 6).
 */
function cacheRolledBack(Closure $change): void
{
    try {
        DB::transaction(function () use ($change): void {
            $change();

            throw new RuntimeException('Rolled back on purpose.');
        });
    } catch (RuntimeException $rolledBack) {
        expect($rolledBack->getMessage())->toBe('Rolled back on purpose.');
    }
}

/**
 * An Operation whose perform() tells the page cache of this change, runs
 * another Operation inside itself when given one, and fails when told to.
 */
function cacheProbe(CacheKeysFor $change, bool $fails = false, ?BaseOperation $inner = null): BaseOperation
{
    return new class($change, $fails, $inner) extends BaseOperation
    {
        public function __construct(
            private readonly CacheKeysFor $change,
            private readonly bool $fails,
            private readonly ?BaseOperation $inner,
        ) {}

        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            $this->purgeAfterCommit($this->change);
            $this->inner?->execute($input);

            if ($this->fails) {
                throw new RuntimeException('perform() failed on purpose.');
            }

            return null;
        }
    };
}

// Page cache helpers (slice 1.7c)

/**
 * Moves a published item's date $seconds ahead through quick edit, which
 * allows it: the item leaves its lists, its address and its menus until
 * then, and comes back with no Operation to tell the page cache.
 */
function cacheDateAhead(Content $content, User $actor, int $seconds): void
{
    $content->refresh();

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        $content->id, $content->working_revision_id, (string) $actor->id, publishedAt: now()->addSeconds($seconds)->toIso8601String(),
    ));
}

/**
 * The copy of the page at this address a change set aside as stale, or null.
 */
function cacheSetAside(string $uri): ?PageEntry
{
    $key = PageKey::of(Request::create($uri)) ?? throw new LogicException("No page is stored for {$uri}.");

    return app(PageStore::class)->stale($key);
}

/**
 * Another request drawing the page at this address: holds the lock that
 * request would hold, taken from the page store as the page cache takes it,
 * until the test ends or releases it.
 */
function cacheDrawing(string $uri): Lock
{
    $key = PageKey::of(Request::create($uri)) ?? throw new LogicException("No page is stored for {$uri}.");
    $lock = app(PageStore::class)->lock($key, PageCache::LOCK_SECONDS);

    expect($lock->get())->toBeTrue();

    return $lock;
}

// Page cache helpers (slice 1.7d)

/**
 * A key prefix of this test's own on the test Valkey (phpunit.xml's
 * REDIS_PORT, 6380: `docker compose up -d valkey_test` here, a service in
 * CI), since the parallel gate runs twelve processes against one Valkey.
 * Skips the test, saying why, when no Valkey answers, unless
 * TEME_REQUIRE_VALKEY is set, as CI sets it: there the test fails instead,
 * so CI can never pass by skipping the Redis store.
 */
function cacheValkey(): string
{
    try {
        Redis::connection('page-cache')->ping();
    } catch (Throwable $unreachable) {
        if (getenv('TEME_REQUIRE_VALKEY') !== false) {
            throw $unreachable;
        }

        test()->markTestSkipped(sprintf(
            'No Valkey answers at %s:%s, so the Redis page store cannot be tested here. Start one with docker compose up -d valkey_test.',
            (string) config('database.redis.page-cache.host'),
            (string) config('database.redis.page-cache.port'),
        ));
    }

    return 'teme-test-'.getmypid().'-'.uniqid().':';
}

/**
 * Removes every key a test wrote under its prefix; nothing when the test
 * was skipped before it had one. Tests may walk the keyspace; app/ never
 * does.
 */
function cacheOffValkey(?string $prefix): void
{
    if ($prefix === null) {
        return;
    }

    Redis::connection('page-cache')->eval(<<<'LUA'
        local cursor = '0'
        repeat
          local batch = redis.call('SCAN', cursor, 'MATCH', KEYS[1] .. '*', 'COUNT', 1000)
          cursor = batch[1]
          for _, key in ipairs(batch[2]) do redis.call('DEL', key) end
        until cursor == '0'
        return 0
        LUA, 1, $prefix);
}

/**
 * Every key under a test's prefix, the prefix taken off, with its life in
 * seconds (-1 for none), sorted by key.
 *
 * @return array<string, int>
 */
function cacheValkeyKeys(string $prefix): array
{
    $found = Redis::connection('page-cache')->eval(<<<'LUA'
        local found, cursor = {}, '0'
        repeat
          local batch = redis.call('SCAN', cursor, 'MATCH', KEYS[1] .. '*', 'COUNT', 1000)
          cursor = batch[1]
          for _, key in ipairs(batch[2]) do
            table.insert(found, string.sub(key, #KEYS[1] + 1))
            table.insert(found, redis.call('TTL', key))
          end
        until cursor == '0'
        return found
        LUA, 1, $prefix);

    $keys = [];

    foreach (array_chunk(is_array($found) ? $found : [], 2) as [$key, $life]) {
        $keys[(string) $key] = (int) $life;
    }

    ksort($keys);

    return $keys;
}

/**
 * Turns the page cache on with the Redis store, for one test: its keys
 * under a prefix of the test's own (cacheValkey(), which skips the test
 * when no Valkey answers), the site at the host the test client asks for,
 * and a page's life at a minute, as cacheOn() keeps it. Returns the
 * prefix, for cacheOffValkey().
 */
function cacheOnRedis(): string
{
    $prefix = cacheValkey();

    config([
        'page_cache.enabled' => true,
        'page_cache.lifetime' => 60,
        'page_cache.driver' => 'redis',
        'page_cache.stores.redis.prefix' => $prefix,
        'app.url' => 'http://localhost',
    ]);

    return $prefix;
}

// Page cache helpers (slice 1.7d′)

/**
 * Puts Cloudflare in front of the site for one test, with made-up
 * credentials, and answers its API as Cloudflare answers a purge it takes,
 * or with the answer given. No test reaches the real API: TestCase stops
 * every request nothing fakes.
 */
function cacheCdnOn(PromiseInterface|ResponseSequence|Closure|null $answer = null): void
{
    config([
        'page_cache.cdn.driver' => 'cloudflare',
        'page_cache.cdn.cloudflare.zone' => 'zone-test',
        'page_cache.cdn.cloudflare.token' => 'token-test',
        'page_cache.cdn.cloudflare.purges_per_minute' => 5,
    ]);

    Http::fake(['api.cloudflare.com/*' => $answer ?? Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'purge-test']])]);
}

/**
 * The bodies of the purge requests sent to Cloudflare so far, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function cacheCdnSent(): array
{
    return Http::recorded(fn (ApiRequest $request): bool => str_contains($request->url(), 'api.cloudflare.com'))
        ->map(fn (array $sent): array => $sent[0]->data())
        ->values()
        ->all();
}

/**
 * Turns warming on for one test, as a site with a queue worker has it: the
 * queue is `database`, so warming isn't skipped as it is on `sync`, and
 * faked, so the test sees each job and runs it when it chooses.
 */
function cacheWarmOn(): void
{
    config(['page_cache.warm.enabled' => true, 'page_cache.warm.limit' => 20, 'queue.default' => 'database']);
    Queue::fake();
}

// Page cache helpers (slice 1.7d″)

/**
 * Turns the page cache on with the static-file driver, for one test: the
 * file store's folder of this process's own, as cacheOn() makes it, with the
 * web server's folder inside it, and a page's life at an hour, since a page
 * with no more than StaticPages::EARLY seconds left gets no file. Returns the
 * folder, for cacheOff(), which removes both.
 */
function cacheOnStatic(): string
{
    $folder = cacheOn();

    config([
        'page_cache.driver' => 'static',
        'page_cache.stores.static.path' => $folder.'/static',
        'page_cache.lifetime' => 3600,
    ]);

    return $folder;
}

/**
 * Pages put straight into the page store, each at its address with these
 * tags, living an hour, so each gets its file with the static-file driver;
 * each page's HTML is its address in a paragraph: address => tags.
 *
 * @param  array<string, list<string>>  $pages
 */
function cacheFiled(array $pages): void
{
    foreach ($pages as $uri => $tags) {
        cachePage($uri, '<p>'.$uri.'</p>', 3600, $tags);
    }
}

/**
 * The HTML the web server would serve for this path from the static-file
 * driver's folder, or null when there is no file. Each part of the file's
 * name is compared exactly, as a Linux web server finds it, so a file named
 * in other capitals is absent here too, even on a file system that ignores
 * case. Tests may list a folder; app/ never does.
 */
function cacheStaticFile(string $path): ?string
{
    $name = StaticPages::nameFor($path);
    $file = (string) config('page_cache.stores.static.path');

    if ($name === null) {
        return null;
    }

    foreach (explode('/', $name) as $part) {
        if (! is_dir($file) || ! in_array($part, scandir($file) ?: [], true)) {
            return null;
        }

        $file .= '/'.$part;
    }

    return is_file($file) ? (string) file_get_contents($file) : null;
}

/**
 * A web server that follows the static-file driver's shipped settings, as
 * Http::fake()'s answer, for teme:cache:check-static: a GET or HEAD with no
 * query, no teme_auth cookie and an address the router doesn't keep is
 * answered from the static folder, marked STATIC, when its file is there;
 * anything else as PHP answers an address with nothing at it. Both carry the
 * image's security headers. Each flaw named breaks one rule: `cookie` serves
 * a signed-in browser, `headers` drops the security headers from a file,
 * `remembers` keeps serving a file once it is deleted.
 *
 * @param  list<string>  $flaws
 */
function cacheWebServer(array $flaws = []): Closure
{
    $remembered = [];

    return function (ApiRequest $request) use ($flaws, &$remembered): PromiseInterface {
        $parts = parse_url($request->url()) ?: [];
        $path = (string) ($parts['path'] ?? '/');
        $security = ['X-Frame-Options' => 'SAMEORIGIN', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin'];
        $signedIn = preg_match('/(^|;\s*)'.SignedInMarker::COOKIE.'=/', implode('; ', $request->header('Cookie'))) === 1;
        $mayHaveFile = in_array($request->method(), ['GET', 'HEAD'], true)
            && ($parts['query'] ?? '') === ''
            && (! $signedIn || in_array('cookie', $flaws, true))
            && ! StaticPages::reserved($path);
        $html = cacheStaticFile($path) ?? $remembered[$path] ?? null;

        if (! $mayHaveFile || $html === null) {
            return Http::response('<p>Nothing here</p>', 404, ['Content-Type' => 'text/html; charset=UTF-8', ...$security]);
        }

        if (in_array('remembers', $flaws, true)) {
            $remembered[$path] = $html;
        }

        return Http::response($request->method() === 'HEAD' ? '' : $html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Teme-Cache' => 'STATIC',
            'Cache-Control' => 'public, max-age=0, s-maxage=60',
            ...(in_array('headers', $flaws, true) ? [] : $security),
        ]);
    };
}

// Card lookup helpers (slice 1.7e, issue 30)

/**
 * A category with its address, as its archive needs one.
 */
function cardsCategory(string $name): Term
{
    $category = taxonomyTerm('category', $name);
    app(PathProjection::class)->syncTerm($category);

    return $category;
}

/**
 * A published post filed under this category, by an author of its own who
 * has an address, with an image of its own as its featured image: a card
 * that shows an author and an image nothing else on the page shares.
 */
function cardsPost(Term $category, string $title, bool $sticky = false): Content
{
    $author = User::factory()->create()->assignRole('author');
    app(PathProjection::class)->syncUser($author);

    return pageArticle($author, $title, categories: [$category], featured: pageImage($author, 1200, 800), sticky: $sticky);
}

/**
 * How many statements a GET of this public page runs, drawn once first, so
 * the cache store holds what any request finds there (a change's Operation
 * may have made it forget a settings group), then counted in a fresh scope,
 * as a new request or a queue job starts: nothing the first draw kept in the
 * scope, an image remembered or the settings memo, makes it look cheaper.
 */
function cardsStatements(string $uri): int
{
    addrGet($uri)->assertOk();
    app()->forgetScopedInstances();

    return count(dashQueries(fn () => addrGet($uri)->assertOk()));
}

// Comment helpers (slice 1.8a)

/**
 * A new comment ID, as SubmitComment makes one: a lower-case ULID from the
 * clock, so a thread's path order is the order its comments were written.
 */
function cmId(): string
{
    return strtolower((string) Str::ulid(now()));
}

/**
 * A published item, through the real Operations: a post starts open for
 * comments, a page closed.
 */
function cmItem(User $author, string $title = 'Harbour news', string $type = 'post'): Content
{
    return addrPublished($author, $title, $type);
}

/**
 * A comment's body as SubmitComment stores one plain paragraph.
 *
 * @return array<string, mixed>
 */
function cmBody(string $text): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

/**
 * A comments row written straight to the table, for tests of the table's own
 * rules: a guest's approved comment that starts a thread, unless $columns
 * say otherwise. Returns its ID.
 *
 * @param  array<string, mixed>  $columns
 */
function cmRow(Content $item, array $columns = []): string
{
    $id = (string) ($columns['id'] ?? cmId());

    DB::table('comments')->insert(array_replace([
        'id' => $id,
        'content_id' => $item->id,
        'path' => $id.'/',
        'depth' => 1,
        'kind' => 'comment',
        'status' => 'approved',
        'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
        'author_name' => 'Ada Guest',
        'author_email' => 'ada@example.com',
        'body' => json_encode(cmBody('Lovely piece.')),
        'created_at' => now(),
        'updated_at' => now(),
    ], $columns));

    return $id;
}

/**
 * The SQLSTATE PostgreSQL refuses this write with, or null when it takes it.
 * The write runs in a savepoint, so the test's own transaction carries on: a
 * failed statement would otherwise abort it, and every later one fail.
 */
function cmRefusal(Closure $write): ?string
{
    try {
        DB::transaction(fn () => $write());
    } catch (QueryException $refused) {
        return (string) $refused->getCode();
    }

    return null;
}

/**
 * A comment written straight to the table with its item's counts kept, as an
 * Operation keeps them, for tests that aren't about submitting: a guest's,
 * approved, starting a thread, or under $parent, one level deeper.
 *
 * @param  array<string, mixed>  $attributes
 */
function cmComment(Content $item, string $status = 'approved', ?Comment $parent = null, array $attributes = []): Comment
{
    $id = cmId();
    $comment = Comment::query()->create(array_replace([
        'id' => $id,
        'content_id' => $item->id,
        'parent_id' => $parent?->id,
        'path' => ($parent === null ? '' : $parent->path).$id.'/',
        'depth' => ($parent === null ? 0 : $parent->depth) + 1,
        'kind' => 'comment',
        'status' => $status,
        'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
        'author_name' => 'Ada Guest',
        'author_email' => 'ada@example.com',
        'body' => cmBody('Lovely piece.'),
    ], $attributes));

    CommentStats::apply($comment, null, $comment->status);

    return $comment;
}

/**
 * The item's comment_stats row, or null when nothing on it has been counted.
 *
 * @return array<string, mixed>|null
 */
function cmStats(Content $item): ?array
{
    $row = DB::table('comment_stats')->where('content_id', $item->id)->first();

    return $row === null ? null : (array) $row;
}

/**
 * Sets values in a settings group straight in the settings table, merged over
 * what it holds, with the group's cache forgotten, as themeActivate() sets the
 * design: nobody holds the discussion or modules group's ability until 1.8d.
 *
 * @param  array<string, mixed>  $values
 */
function cmSettings(array $values, string $group = 'discussion'): void
{
    $held = json_decode((string) (DB::table('settings')->where('group_key', $group)->value('overrides') ?? '{}'), true);

    DB::table('settings')->updateOrInsert(['group_key' => $group], ['overrides' => json_encode([...(is_array($held) ? $held : []), ...$values])]);
    Cache::memo()->forget(SettingsRepository::cacheKey($group));
}

/**
 * What the moderator is shown for a comment: a guest's plain words, the first
 * from this browser, unless $overrides say otherwise.
 *
 * @param  array<string, mixed>  $overrides  CommentCandidate's parameters, by name
 */
function cmCandidate(array $overrides = []): CommentCandidate
{
    return new CommentCandidate(...array_replace([
        'contentId' => cmId(),
        'parentId' => null,
        'text' => 'Lovely piece.',
        'links' => 0,
        'authorName' => 'Ada Guest',
        'authorEmail' => 'ada@example.com',
        'authorUrl' => null,
        'authorUserId' => null,
        'ip' => '203.0.113.7',
        'userAgent' => 'Mozilla/5.0 (Test)',
        'returning' => false,
        'itemUrl' => null,
    ], $overrides));
}

/**
 * A comment through SubmitComment's real input: a guest's, Ada Guest's, from
 * one browser and one address, unless $overrides say otherwise. A signed-in
 * writer is `['actorId' => …, 'commenterToken' => null]`.
 *
 * @param  array<string, mixed>  $overrides  SubmitCommentInput's parameters, by name
 */
function cmSubmit(Content $item, string $text = 'Lovely piece.', array $overrides = []): SubmittedComment
{
    return app(SubmitComment::class)->execute(new SubmitCommentInput(...array_replace([
        'contentId' => $item->id,
        'text' => $text,
        'name' => 'Ada Guest',
        'email' => 'ada@example.com',
        'commenterToken' => 'browser-one-0123456789abcdef',
        'ip' => '203.0.113.7',
        'userAgent' => 'Mozilla/5.0 (Test)',
    ], $overrides)));
}

/**
 * Moves a comment through ModerateComment's real input, and returns its status after.
 */
function cmModerate(Comment $comment, string $to, User $actor): string
{
    return app(ModerateComment::class)->execute(new ModerateCommentInput($comment->id, $to, (string) $actor->id))->after;
}

/**
 * Deletes a comment for good through DeleteCommentPermanently's real input:
 * 'deleted', or 'removed' when a tombstone keeps its place.
 */
function cmDelete(Comment $comment, User $actor): string
{
    return app(DeleteCommentPermanently::class)->execute(new CommentActionInput($comment->id, (string) $actor->id));
}

/**
 * Edits a comment through EditComment's real input: its name, email address,
 * website and words as they are, unless $fields say otherwise.
 *
 * @param  array<string, string|null>  $fields  EditCommentInput's parameters, by name
 */
function cmEdit(Comment $comment, User $actor, array $fields = []): EditedComment
{
    return app(EditComment::class)->execute(new EditCommentInput(...[
        'commentId' => $comment->id,
        'name' => (string) $comment->author_name,
        'email' => (string) $comment->author_email,
        'url' => $comment->author_url,
        'text' => CommentText::plain($comment->body ?? []),
        'actorId' => (string) $actor->id,
        ...$fields,
    ]));
}

/**
 * A reply written in the admin through ReplyToComment's real input.
 */
function cmReply(Comment $comment, User $actor, string $text = 'Thanks for this.'): PostedReply
{
    return app(ReplyToComment::class)->execute(new ReplyToCommentInput($comment->id, $text, (string) $actor->id));
}

/**
 * When a comment's status last changed, as stored, to the microsecond
 * (1.8c′'s D2).
 */
function cmChangedAt(Comment $comment): CarbonImmutable
{
    return CarbonImmutable::parse((string) DB::table('comments')->where('id', $comment->id)->value('status_changed_at'));
}

/**
 * A comment one minute after the moment the test was last at, made through
 * cmComment(), so IDs, which come from the clock, sort in the order a test
 * makes them: lists newest first have one order to assert.
 */
function cmNext(Content $item, string $status = 'approved', ?Comment $parent = null, array $attributes = []): Comment
{
    test()->travel(1)->minutes();

    return cmComment($item, $status, $parent, $attributes);
}

/**
 * One page of the Comments screen's list as $viewer reads it, through
 * CommentList and its real request.
 *
 * @param  array<string, mixed>  $request  CommentListRequest's arguments, less the viewer
 *
 * @return array{rows: list<array<string, mixed>>, newer: string|null, older: string|null, view: string}
 */
function cmList(User $viewer, array $request = []): array
{
    return app(CommentList::class)->page(new CommentListRequest(...['viewerId' => (int) $viewer->id, ...$request]));
}

/**
 * The Comments screen as $viewer opens it, with $query in its address.
 *
 * @param  array<string, string>  $query
 */
function cmScreen(User $viewer, array $query = []): TestResponse
{
    return test()->actingAs($viewer)->get(route('comments.index', $query));
}

/**
 * Empty spam or Empty trash as the Comments screen sends it, from the list's
 * own address: the Spam view opened now, unless $fields say otherwise.
 *
 * @param  array<string, mixed>  $fields
 */
function cmEmpty(User $actor, array $fields = []): TestResponse
{
    $fields = [...['view' => 'spam', 'openedAt' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')], ...$fields];

    return test()->actingAs($actor)
        ->from(route('comments.index', array_filter(['view' => $fields['view'], 'item' => $fields['item'] ?? null, 'search' => $fields['search'] ?? null])))
        ->post(route('comments.empty'), $fields);
}

/**
 * The email the last $notice sent to $to would be, as MailMessage, from
 * Notification::fake(), which records notifyNow() too.
 *
 * @param  class-string  $notice
 */
function cmMail(User $to, string $notice): MailMessage
{
    $sent = Notification::sent($to, $notice);

    expect($sent)->not->toBeEmpty();

    return $sent->last()->toMail($to);
}

/**
 * Runs a queued job now, its dependencies from the container, as a worker
 * would: for a job Queue::fake() caught, after the test has changed things.
 */
function cmRunJob(object $job): void
{
    app()->call([$job, 'handle']);
}

/**
 * Signs $person in through the admin's own form, in a real browser, as
 * TwoEditorsTest does, and returns the page at Home.
 */
function cmSignIn(User $person): mixed
{
    return visit('/admin/login')
        ->type('email', $person->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin');
}

/**
 * Runs axe's target-size rule (WCAG 2.2, 2.5.8) on the page, which the
 * Browser plugin's axe ships disabled (1.8c's D24): the targets smaller than
 * 24 pixels, by selector, and how many targets it checked, so a run that
 * checked nothing can't pass (B-358).
 *
 * @return array{small: list<string>, checked: int}
 */
function cmSmallTargets(mixed $page): array
{
    $result = cmScriptArray($page, <<<'JS'
        async () => {
            const run = await window.axe.run(document, { runOnly: { type: 'rule', values: ['target-size'] }, rules: { 'target-size': { enabled: true } } });
            const count = (rules) => rules.reduce((sum, rule) => sum + rule.nodes.length, 0);

            return {
                small: run.violations.flatMap((violation) => violation.nodes.map((node) => node.target.join(' '))),
                checked: count(run.passes) + count(run.violations),
            };
        }
        JS, 'cmSmallTargets');

    return [
        'small' => is_array($result['small'] ?? null) ? array_values(array_map(strval(...), $result['small'])) : ['axe did not answer'],
        'checked' => (int) ($result['checked'] ?? 0),
    ];
}

/**
 * axe's violations on the whole page, of every impact, as the Browser
 * plugin's assertNoAccessibilityIssues(3) finds them, read once nothing on the
 * page is mid-transition. The plugin's own check waits first for the network
 * to fall quiet within one second, which a busy machine misses, and it reads
 * a fading colour halfway (#238). axe runs once, allowed the plugin's whole
 * timeout: script() tries in one-second attempts, and on a slow machine a
 * second attempt meets the first still running, "Axe is already running".
 *
 * @return list<string>
 */
function cmAxeViolations(mixed $page): array
{
    cmWaitUntil($page, "document.getAnimations().every((animation) => !(animation instanceof CSSTransition) || animation.playState !== 'running')");

    $result = cmScriptArray($page, <<<'JS'
        async () => (await window.axe.run(document)).violations.flatMap((violation) => violation.nodes.map((node) => violation.id + ': ' + node.target.join(' ')))
        JS, 'cmAxeViolations', once: true);

    return array_values(array_map(strval(...), $result));
}

/**
 * The answer to $script, an expression or a function as the plugin's script()
 * takes them, checked to be the answer to this call. The plugin's client
 * takes the first answer it reads as its own, whichever call it answers, so
 * after a call left part-way, each script() reads the answer to the call
 * before: an array asked for arrives as true. Each answer here carries a
 * mark of its own question; one that doesn't is refused, the answers still
 * waiting are read off, and the question is asked once more. $once runs it
 * in one attempt with the plugin's whole timeout, for a script that can take
 * longer than script()'s one-second attempts and mustn't run twice at once.
 */
function cmAnswer(mixed $page, string $script, string $helper = 'cmAnswer', bool $once = false): mixed
{
    $wrong = null;

    for ($ask = 1; $ask <= 2; $ask++) {
        $mark = bin2hex(random_bytes(8));
        $marked = "async () => { const asked = ({$script}); return { mark: '{$mark}', answer: typeof asked === 'function' ? await asked() : await asked }; }";
        $answer = $once
            ? Playwright::usingTimeout(Playwright::timeout(), fn (): mixed => $page->page()->evaluate($marked))
            : $page->script($marked);

        if (is_array($answer) && ($answer['mark'] ?? null) === $mark) {
            return $answer['answer'] ?? null;
        }

        $wrong = $answer;
        cmReadOffAnswers($page);
    }

    throw new ExpectationFailedException(sprintf('%s: the browser answered %s, the answer to another call, where this call\'s own was expected.', $helper, json_encode($wrong)));
}

/**
 * The answer to $script, which must be an array: an object or a list, as
 * JSON gives them. Through cmAnswer(), so an answer to another call is
 * refused; an answer of another shape fails here, naming $helper.
 *
 * @return array<array-key, mixed>
 */
function cmScriptArray(mixed $page, string $script, string $helper = 'cmScriptArray', bool $once = false): array
{
    $answer = cmAnswer($page, $script, $helper, $once);

    if (! is_array($answer)) {
        throw new ExpectationFailedException(sprintf('%s: the browser answered %s where an array was expected.', $helper, json_encode($answer)));
    }

    return $answer;
}

/**
 * Reads off every answer waiting for a call that was left part-way: the
 * plugin reads a style tag's answer by reading every message up to its own,
 * and none of them is taken as an answer. An empty comment is all it adds.
 */
function cmReadOffAnswers(mixed $page): void
{
    try {
        $page->page()->addStyleTag('/* answers read off */');
    } catch (ExpectationFailedException) {
        // An error waiting for another call ends that read first; the next reads on from it.
        $page->page()->addStyleTag('/* answers read off */');
    }
}

/**
 * Asserts there were no JavaScript errors on the page, as the plugin's
 * assertNoJavaScriptErrors() does, reading them through cmScriptArray(): the
 * plugin's own reader takes whatever answer comes first, and failed with
 * "Return value must be of type array, true returned".
 */
function cmNoJavaScriptErrors(mixed $page): mixed
{
    $errors = cmScriptArray($page, 'window.__pestBrowser.jsErrors || []', 'cmNoJavaScriptErrors');

    expect($errors)->toBeEmpty(sprintf(
        'Expected no JavaScript errors on the page, but found %s: %s',
        count($errors),
        implode(', ', array_map(fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? '') : (string) $error, $errors)),
    ));

    return $page;
}

/**
 * Waits until the JavaScript expression $condition is true on the page, for
 * as long as the plugin's own timeout. The plugin's assertSee(),
 * assertSeeIn(), assertDisabled() and assertNotPresent() look once and answer
 * at once, so after a click or a navigation they race the render (issue #83);
 * click(), hover() and fill() are the ones that wait for their element. Its
 * waitForFunction() never sends its message, so this polls in the page.
 */
function cmWaitUntil(mixed $page, string $condition): mixed
{
    $timeout = Playwright::timeout();
    $met = cmAnswer($page, "async () => { const until = Date.now() + {$timeout}; while (Date.now() < until) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 50)); } return false; }", 'cmWaitUntil');

    expect($met)->toBeTrue("The page did not satisfy [{$condition}] within {$timeout} ms.");

    return $page;
}

/**
 * Goes to $url and returns once the page has loaded, allowing the plugin's
 * whole timeout for it. The plugin's navigate() tries in one-second attempts;
 * when a page takes longer, a later attempt can stop at the earlier one's load
 * event and leave its own answer, a timeout, unread, and the next call reads
 * that answer instead of its own: a click fails with "Timeout 1000ms
 * exceeded", or every script() after it answers the call before
 * (pestphp/pest#1910). One attempt with the whole timeout leaves nothing.
 */
function cmNavigate(mixed $page, string $url): mixed
{
    return $page->navigate($url, ['timeout' => Playwright::timeout()]);
}

/**
 * Waits until the editor has arrived: its writing surface is up and the admin
 * sidebar has finished folding, the width transition the editor starts on
 * mounting. A pointer that meets the header while it moves is delivered twice,
 * so a toggle's click lands as two and undoes itself (issue #83).
 */
function cmEditorSettled(mixed $page): mixed
{
    return cmWaitUntil($page, <<<'JS'
        document.querySelector('.teme-prose') !== null
            && document.querySelector('[data-slot="sidebar"][data-state="collapsed"]') !== null
            && document.getAnimations().every((animation) => !(animation instanceof CSSTransition) || animation.playState !== 'running')
        JS);
}

/**
 * Opens the editor's Search and social with its Edit button and waits until
 * its fields show (I-3124); the section starts closed on every visit. Call it
 * after cmEditorSettled(), so the click lands once.
 */
function cmOpenSearch(mixed $page): mixed
{
    $page->click('[data-test="search-toggle"]');

    return cmWaitUntil($page, "document.querySelector('[data-test=\"search-toggle\"]')?.getAttribute('aria-expanded') === 'true' && document.getElementById('editor-search-fields')?.hidden === false");
}

// Comment helpers (slice 1.8b)

/**
 * The comment form posted as a browser posts it: Ada Guest's, on this item,
 * from the page's own origin, unless $fields, $headers or $cookies say
 * otherwise; $headers replace the default whole. Through the kernel, as
 * addrGet() asks, so the path is sent as it is.
 *
 * @param  array<string, string>  $fields
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $cookies
 */
function cmPost(Content $item, array $fields = [], array $headers = ['Sec-Fetch-Site' => 'same-origin'], array $cookies = []): TestResponse
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create('/_teme/comments', 'POST', array_replace([
        'path' => (string) Addresses::relativeUrl($item),
        'parent' => '',
        'text' => 'Lovely piece.',
        'name' => 'Ada Guest',
        'email' => 'ada@example.com',
        'url' => '',
        'token' => '',
        'subject' => '',
    ], $fields), $cookies, [], $server);

    return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
}

// Comment helpers (slice 1.8b′)

/**
 * The question core's script asks for this browser's own comments: the
 * item's address, and the key in its header unless $key is null. Through
 * the kernel, as addrGet() asks, so nothing is added to the request.
 */
function cmPending(string $path, ?string $key): TestResponse
{
    $request = Request::create('/_teme/comments/pending', 'GET', ['path' => $path], [], [], $key === null ? [] : ['HTTP_X_TEME_COMMENTER' => $key]);

    return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
}

// Document head helpers (I-228)

/**
 * Registers a head contributor for one test, speaking for $precedence,
 * which gives these entries on every page.
 *
 * @param  list<MetaTag|LinkTag|JsonLdNode>  $entries
 */
function headWith(Precedence $precedence, array $entries): void
{
    $name = 'test.head.'.uniqid();

    app()->instance($name, new readonly class($precedence, $entries) implements HeadContributor
    {
        /**
         * @param  list<MetaTag|LinkTag|JsonLdNode>  $entries
         */
        public function __construct(private Precedence $precedence, private array $entries) {}

        public function precedence(): Precedence
        {
            return $this->precedence;
        }

        public function entries(DocumentPage $page): array
        {
            return $this->entries;
        }
    });
    app()->tag([$name], DocumentMeta::CONTRIBUTORS);
}

/**
 * Registers a placement contributor for one test, speaking for $precedence,
 * which gives these fragments at $placement on every page.
 *
 * @param  list<Fragment>  $fragments
 */
function placeWith(Precedence $precedence, Placement $placement, array $fragments): void
{
    $name = 'test.placement.'.uniqid();

    app()->instance($name, new readonly class($precedence, $placement, $fragments) implements PlacementContributor
    {
        /**
         * @param  list<Fragment>  $fragments
         */
        public function __construct(private Precedence $precedence, private Placement $placement, private array $fragments) {}

        public function precedence(): Precedence
        {
            return $this->precedence;
        }

        public function fragments(Placement $placement, DocumentPage $page): array
        {
            return $placement === $this->placement ? $this->fragments : [];
        }
    });
    app()->tag([$name], Placements::CONTRIBUTORS);
}

// The head's helpers (I-290)

/**
 * The page at $uri, parsed, once it has answered $status.
 */
function headDom(string $uri, int $status = 200): DOMXPath
{
    return dtDom((string) addrGet($uri)->assertStatus($status)->getContent());
}

/**
 * What an XPath expression reads in a parsed page, '' when nothing matches.
 */
function headValue(DOMXPath $dom, string $path): string
{
    return (string) $dom->evaluate("string({$path})");
}

/**
 * A published post with these fields, terms and nodes, through the real
 * Operations; one paragraph of text when no nodes are given.
 *
 * @param  array<string, mixed>  $fields
 * @param  list<Term>  $terms
 * @param  list<array<string, mixed>>  $nodes
 */
function headPost(User $author, string $title, array $fields = [], array $terms = [], array $nodes = [], ?Media $featured = null): Content
{
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The ferry fares go up in the spring.']]];

    return pagePublish($author, $title, $nodes === [] ? [$paragraph] : $nodes, $fields, array_map(fn (Term $term): string => $term->id, $terms), $featured);
}

/**
 * A term with its archive's address.
 */
function headTerm(string $taxonomy, string $name, ?string $description = null): Term
{
    $term = taxonomyTerm($taxonomy, $name);

    if ($description !== null) {
        $term->forceFill(['description' => $description])->save();
    }

    app(PathProjection::class)->syncTerm($term);

    return $term;
}

// Redirect helpers (I-297)

/**
 * A redirect row written straight to the table from what a form would ask
 * for, read as CreateRedirect reads it but never checked against the other
 * redirects, for tests of what reads them. Returns its ID.
 */
function rdSaved(string $from, string $to = '', int $status = 301, ?string $item = null, ?string $file = null, bool $on = true): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('redirects')->insert([
        ...RedirectProposal::read($from, $status, $to, $item, $file, '')->columns(),
        'id' => $id,
        'origin' => 'manual',
        'enabled' => $on,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // As a change through an Operation would after its commit.
    Cache::memo()->forget(RedirectLookup::PATTERNS_KEY);

    return $id;
}

/**
 * Rows of the 404 log written straight to the table, path in the source's
 * form => times asked for, first seen a day ago and last seen now, each
 * from this host if one is given.
 *
 * @param  array<string, int>  $counts
 */
function rdLogged(array $counts, ?string $from = null): void
{
    foreach ($counts as $path => $count) {
        DB::table('not_found_log')->insert([
            'path' => (string) $path,
            'count' => $count,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'referrer_host' => $from,
        ]);
    }
}

// Feed helpers (I-299)

/**
 * A feed's XML parsed for XPath, with the namespaces a feed uses registered
 * under the prefixes it uses. Fails the test, saying why, when the text is
 * not well-formed XML.
 */
function feedParse(string $xml): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xml);
    $problems = array_map(fn (LibXMLError $error): string => trim($error->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($loaded)->toBeTrue('The feed is not well-formed XML: '.implode(' | ', $problems));

    $xpath = new DOMXPath($document);

    foreach (['content' => 'http://purl.org/rss/1.0/modules/content/', 'dc' => 'http://purl.org/dc/elements/1.1/', 'atom' => 'http://www.w3.org/2005/Atom', 'media' => 'http://search.yahoo.com/mrss/'] as $prefix => $namespace) {
        $xpath->registerNamespace($prefix, $namespace);
    }

    return $xpath;
}

/**
 * The feed at this address, asked for as a reader would: answered 200 as
 * RSS, and parsed.
 */
function feedDom(string $uri): DOMXPath
{
    $response = addrGet($uri)->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    return feedParse((string) $response->getContent());
}

// Site code helpers (I-288)

/**
 * Runs $run as the trusted operator, so no Operation asks for a password,
 * then puts back whatever answered for elevation before.
 */
function scElevated(Closure $run): mixed
{
    $held = app(Elevation::class);
    app()->instance(Elevation::class, new OperatorElevation);

    try {
        return $run();
    } finally {
        app()->instance(Elevation::class, $held);
    }
}

/**
 * An entry added through SaveSiteCode by $admin, as the operator: an
 * analytics script in the head, on, unless told otherwise.
 */
function scAdd(User $admin, string $name = 'Visitor analytics', string $placement = 'head', string $code = '<script defer data-site="ledger" src="https://stats.example.org/script.js"></script>', bool $on = true): SiteCodeEntry
{
    /** @var array{id: string} $result */
    $result = scElevated(fn (): array => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput(null, $name, $placement, $code, $on, (string) $admin->id)));

    return SiteCodeEntry::query()->findOrFail($result['id']);
}

/**
 * The security log's site code events, oldest first: each one's type, who
 * made the change, and its context with its keys sorted, since JSONB keeps
 * no order. Compare an event with scEvent().
 *
 * @return list<array{type: string, user_id: int|null, context: array<string, mixed>}>
 */
function scLog(): array
{
    return DB::table('security_events')->where('type', 'like', 'site_code.%')->orderBy('id')->get()
        ->map(fn (object $row): array => scEvent((string) $row->type, $row->user_id === null ? null : (int) $row->user_id, (array) json_decode((string) $row->context, true)))
        ->values()
        ->all();
}

/**
 * One event as scLog() gives it.
 *
 * @param  array<string, mixed>  $context
 *
 * @return array{type: string, user_id: int|null, context: array<string, mixed>}
 */
function scEvent(string $type, ?int $userId, array $context): array
{
    ksort($context);

    return ['type' => $type, 'user_id' => $userId, 'context' => $context];
}

/**
 * A session holding a password confirmation made now (I-864).
 *
 * @return array<string, int>
 */
function scConfirmed(): array
{
    return ['auth.password_confirmed_at' => now()->getTimestamp()];
}

// Import helpers (I-332)

/**
 * The resolver the address check asks, answering these hosts with these
 * addresses and nothing else, so no test looks a name up.
 *
 * @param  array<string, list<string>>  $hosts
 */
function importResolve(array $hosts): void
{
    app()->instance(HostResolver::class, new FakeHostResolver($hosts));
}

/**
 * The fake companion plugin for this test (I-334): old.example resolves to
 * a public address, local sources are off, every request is answered from
 * tests/Fixtures/Import/Companion, and any other request fails the test.
 */
function importFake(): FakeCompanion
{
    config(['teme.import.local_sources' => false]);
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP]]);
    $fake = new FakeCompanion;
    Http::preventStrayRequests();
    Http::fake(fn (ApiRequest $request): PromiseInterface => $fake->respond($request));

    return $fake;
}

/**
 * The fake companion's site, connected through ConnectImportSource as
 * $actor, or as the operator. Call importFake() first.
 */
function importSource(?User $actor = null): ImportSource
{
    $connect = fn (): array => app(ConnectImportSource::class)->execute(new ConnectImportSourceInput(
        FakeCompanion::SITE,
        FakeCompanion::USER,
        FakeCompanion::PASSWORD,
        $actor === null ? null : (string) $actor->id,
    ));

    $result = $actor === null ? scElevated($connect) : $connect();

    return ImportSource::query()->findOrFail($result['sourceId']);
}

/**
 * Registers these phases for this test, as a part's provider would (I-349).
 */
function importPhase(object ...$phases): void
{
    foreach ($phases as $phase) {
        // By object, not class: two instances of one anonymous class are two phases.
        $name = $phase::class.'#'.spl_object_id($phase);
        app()->instance($name, $phase);
        app()->tag([$name], ImportPhases::TAG);
    }
}

/**
 * Starts an import of $source as $actor (their password confirmed, as the
 * operator's shell does it), or as the operator, and lets the synchronous
 * queue carry it to its end. The run, fresh.
 *
 * @param  array<string, mixed>  $choices
 * @param  array<string, mixed>  $options
 */
function importRun(ImportSource $source, ?User $actor = null, ImportRunKind $kind = ImportRunKind::Full, array $choices = [], array $options = ['pause_ms' => 0]): ImportRun
{
    $start = fn (): array => app(StartImportRun::class)->execute(
        new StartImportRunInput($source->id, $kind, $options, $choices, $actor === null ? null : (string) $actor->id),
    );

    if ($actor === null) {
        /** @var array{runId: string} $result */
        $result = scElevated($start);

        return ImportRun::query()->findOrFail($result['runId']);
    }

    // A person's confirmed password lets them start or carry on a run, and
    // nothing more: on a worker, a person's jobs bind no elevation.
    $held = app(Elevation::class);
    app()->instance(Elevation::class, new class implements Elevation
    {
        public function ensure(OperationInput $input): void
        {
            if (! $input instanceof StartImportRunInput && ! $input instanceof ContinueImportRunInput) {
                throw new ElevationRequiredException;
            }
        }

        public function isOperator(): bool
        {
            return false;
        }
    });

    try {
        /** @var array{runId: string} $result */
        $result = $start();
    } finally {
        app()->instance(Elevation::class, $held);
    }

    return ImportRun::query()->findOrFail($result['runId']);
}

/**
 * The seam's own two fixture phases, registered alone, setting aside every
 * phase the app registered at boot, so the engine's tests run these and no
 * other; and the table the people phase writes. A list phase over the
 * companion's users at order 205, and a step at 1205.
 */
function importPeople(): PeoplePhase
{
    if (! Schema::hasTable('fixture_people')) {
        Schema::create('fixture_people', function (Blueprint $table): void {
            $table->integer('wordpress_id')->primary();
            $table->string('name');
        });
    }

    $people = new PeoplePhase;
    importPhaseAlone($people, new NotesStep);

    return $people;
}

/**
 * Registers these phases alone for this test, setting aside every phase the
 * app registered at boot, so a run does these and no other.
 */
function importPhaseAlone(object ...$phases): void
{
    // The container keeps its tags in a protected list; this test's app starts it again.
    (fn () => $this->tags[ImportPhases::TAG] = [])->call(app());

    importPhase(...$phases);
}

// The downloader's and Import images' helpers (I-453)

/**
 * Outside sites for a download, with no network: each host resolves to the
 * addresses given, local sources are off, each address pattern answers as
 * given, and any other request fails the test. An answer served more than
 * once is a closure: a sink reads a shared body once.
 *
 * @param  array<string, list<string>>  $hosts
 * @param  array<string, mixed>  $answers  Http::fake()'s patterns and answers
 */
function dlServe(array $hosts, array $answers): void
{
    config(['teme.import.local_sources' => false]);
    importResolve($hosts);
    Http::preventStrayRequests();
    Http::fake($answers);
}

/**
 * A real JPEG's bytes, for a site to answer with.
 */
function dlJpeg(int $width = 640, int $height = 480): string
{
    return (string) file_get_contents(mediaJpeg($width, $height));
}

/**
 * The media library's limits (max_upload_mb, max_megapixels), set through
 * UpdateSettings by an administrator. RoleSeeder must have run.
 *
 * @param  array<string, int>  $values
 */
function dlMediaLimits(array $values): void
{
    $admin = User::factory()->create()->assignRole('administrator');

    scElevated(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('media', $values, [], [], 0, (string) $admin->id)));
}

// The block converter's test helpers (I-335).

/**
 * One block as the companion plugin sends it, with parse_blocks()' keys. In
 * $html, '%s' marks where the inner blocks go; innerContent holds a null
 * for each, as WordPress's parser writes it.
 *
 * @param  array<string, mixed>  $attrs
 * @param  list<array<string, mixed>>  $inner
 *
 * @return array<string, mixed>
 */
function wpBlock(?string $name, string $html = '', array $attrs = [], array $inner = []): array
{
    [$before, $after] = array_pad(explode('%s', $html, 2), 2, '');

    return [
        'blockName' => $name,
        'attrs' => $attrs,
        'innerBlocks' => $inner,
        'innerHTML' => $before.$after,
        'innerContent' => $inner === [] ? [$before.$after] : [$before, ...array_fill(0, count($inner), null), $after],
    ];
}

/**
 * The converter's answer for these blocks, from an old site at
 * https://old.example whose attachments and files Teme has as given.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @param  array<int, string>  $attachments
 * @param  array<string, string>  $files
 */
function wpConvert(array $blocks, array $attachments = [], array $files = [], ?string $uploads = null): Conversion
{
    return app(BlockConverter::class)->convert($blocks, new ConversionSite('https://old.example', $uploads), new KnownMedia($attachments, $files));
}

/**
 * The node with every block's and card's ID taken out, each first checked
 * to be an upper-case ULID, so a test can compare the rest.
 *
 * @param  array<mixed>  $node
 *
 * @return array<mixed>
 */
function wpWithoutIds(array $node): array
{
    if (isset($node['attrs']) && is_array($node['attrs']) && array_key_exists('id', $node['attrs'])) {
        expect($node['attrs']['id'])->toMatch(DocumentSchema::NODE_ID);
        unset($node['attrs']['id']);

        // A block whose only attribute was its id had none before (I-3362).
        if ($node['attrs'] === []) {
            unset($node['attrs']);
        }
    }

    if (isset($node['content']) && is_array($node['content'])) {
        $node['content'] = array_map(fn (array $child): array => wpWithoutIds($child), $node['content']);
    }

    return $node;
}

/**
 * Asserts the document is what Teme stores: DocumentNormaliser leaves it as
 * it is, and DocumentValidator accepts it as a finished document.
 *
 * @param  array<mixed>  $document
 */
function wpAccepted(array $document): void
{
    expect(DocumentNormaliser::normalise($document))->toBe($document);

    app(DocumentValidator::class)->validate($document);
}

/**
 * What the editor's own schema says of each document, through
 * tests/Fixtures/import-editor-check.mjs: null where it accepts it, else
 * its refusal. Skips the test when Node is missing.
 *
 * @param  array<string, array<mixed>>  $documents
 *
 * @return array<string, string|null>
 */
function wpEditorCheck(array $documents): array
{
    return guardNode('import-editor-check.mjs', [
        'manifest' => CardManifest::forBrowser(app(CardRegistry::class)),
        'documents' => $documents,
    ]);
}

// Imported content card helpers (I-329)

/**
 * A run of text in an Imported content card's tree.
 *
 * @return array{text: string}
 */
function icText(string $text): array
{
    return ['text' => $text];
}

/**
 * An element in an Imported content card's tree, with its parts and its
 * attributes when it has any.
 *
 * @param  list<array<string, mixed>>  $children
 * @param  array<string, mixed>  $attrs
 *
 * @return array<string, mixed>
 */
function icEl(string $element, array $children = [], array $attrs = []): array
{
    return ['element' => $element, ...($attrs === [] ? [] : ['attrs' => $attrs]), ...($children === [] ? [] : ['children' => $children])];
}

/**
 * An Imported content card node holding this tree: a new node ID, from an
 * HTML block, not kept, and these attributes over them.
 *
 * @param  list<array<string, mixed>>  $markup
 * @param  array<string, mixed>  $attrs
 *
 * @return array<string, mixed>
 */
function icNode(array $markup, array $attrs = []): array
{
    return ['type' => 'imported', 'attrs' => [
        'id' => (string) Str::ulid(), 'markup' => $markup, 'sourceKind' => 'html-block', 'sourceName' => null, 'kept' => false, ...$attrs,
    ]];
}

/**
 * The card's fixture document, tests/Fixtures/Documents/imported.json.
 *
 * @return array<string, mixed>
 */
function icFixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/Documents/imported.json')), true);
}

// Links by ID (I-1702)

/**
 * An author with an address who has published one article: whom a link by
 * ID to a person draws, and whom the link box offers.
 */
function lkAuthor(string $name): User
{
    $author = User::factory()->create(['name' => $name])->assignRole('author');
    app(PathProjection::class)->syncUser($author);
    addrPublished($author, 'A first story');

    return $author->refresh();
}

/**
 * An image in the library with known sizes: a thumbnail and two widths, the
 * widest 1,280 pixels, written over what the upload made, as
 * edFixtureMedia() does.
 */
function lkImage(User $actor, string $name = 'Harbour.jpg'): Media
{
    $image = mediaUpload($actor, mediaJpeg(1600, 1000), $name, allowDuplicate: true);
    $variants = [];

    foreach (['thumb' => 300, 'w640' => 640, 'w1280' => 1280] as $key => $width) {
        $variants[$key] = ['path' => "{$image->id}/v1/harbour-{$key}.webp", 'width' => $width, 'height' => intdiv($width * 5, 8), 'bytes' => 1000];
    }

    DB::table('media')->where('id', $image->id)->update(['variants' => json_encode($variants)]);

    return $image->refresh();
}

/**
 * A paragraph whose words link to this address, with any other attributes
 * the stored link carries.
 *
 * @param  array<string, mixed>  $attrs
 *
 * @return array<string, mixed>
 */
function lkLink(string $href, string $text, array $attrs = []): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href, ...$attrs]]]]]];
}

// Tools › Import's helpers (I-227)

/**
 * The companion's answer to analyze, from its fixture, with these sections
 * of its report replaced key by key (deeply), and these other keys of its
 * report; for importFake()->items('analyze', …).
 *
 * @param  array<string, mixed>  $sections
 * @param  array<string, mixed>  $report
 *
 * @return array<string, mixed>
 */
function tiReport(array $sections = [], array $report = []): array
{
    $answer = (array) json_decode((string) file_get_contents(base_path('tests/Fixtures/Import/Companion/analyze.json')), true, flags: JSON_THROW_ON_ERROR);
    $answer['report']['sections'] = array_replace_recursive($answer['report']['sections'], $sections);
    $answer['report'] = array_replace($answer['report'], $report);

    return $answer;
}

// Comments import's helpers: Tests\Fixtures\Import\CommentImports (I-341).

// Menus import's helpers: Tests\Fixtures\Import\MenuImports (I-343).

// Addresses', redirects', settings' and the check's import helpers are Tests\Fixtures\Import\OldSite's (I-393).

// The second pass's, fidelity's and catch-up's helpers are Tests\Fixtures\Import\SecondPassImports (I-333).

// Select all matching's helpers: Tests\Fixtures\Jobs\BulkRuns (I-143).

// Uploaded for's helpers live in its own test files, prefixed uf (I-553).

// Private and password-protected items' helpers are Tests\Fixtures\Contents\Visibilities (I-1700).

// Sitemaps' helpers (I-296)

/**
 * A sitemap's XML parsed for XPath, its namespaces under the prefixes `s`
 * (the protocol's) and `image`. Fails the test, saying why, when the text
 * is not well-formed XML.
 */
function smParse(string $xml): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xml);
    $problems = array_map(fn (LibXMLError $error): string => trim($error->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($loaded)->toBeTrue('The sitemap is not well-formed XML: '.implode(' | ', $problems));

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $xpath->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');

    return $xpath;
}

/**
 * Every address a sitemap or its index lists, in its order.
 *
 * @return list<string>
 */
function smLocs(string $xml): array
{
    $locs = [];

    foreach (smParse($xml)->query('//s:loc') ?: [] as $node) {
        $locs[] = $node->textContent;
    }

    return $locs;
}

// Structured data helpers (I-698)

/**
 * The graph of the page at $uri, its nodes as written, once it has answered
 * $status; [] when it has no JSON-LD.
 *
 * @return list<array<string, mixed>>
 */
function sdGraph(string $uri, int $status = 200): array
{
    $script = headValue(headDom($uri, $status), '//head/script[@type="application/ld+json"]');

    return $script === '' ? [] : array_values((array) (json_decode($script, true, flags: JSON_THROW_ON_ERROR)['@graph'] ?? []));
}

/**
 * The node of a graph with this @id, or null.
 *
 * @param  list<array<string, mixed>>  $graph
 *
 * @return array<string, mixed>|null
 */
function sdNode(array $graph, string $id): ?array
{
    foreach ($graph as $node) {
        if (($node['@id'] ?? null) === $id) {
            return $node;
        }
    }

    return null;
}

/**
 * Registers a graph filter for one test, speaking for $precedence, which
 * hands the merged graph to $filter on every page.
 *
 * @param  Closure(list<array<string, mixed>>): list<array<string, mixed>>  $filter
 */
function sdFilterWith(Precedence $precedence, Closure $filter): void
{
    $name = 'test.graph.'.uniqid();

    app()->instance($name, new readonly class($precedence, $filter) implements GraphFilter
    {
        public function __construct(private Precedence $precedence, private Closure $filter) {}

        public function precedence(): Precedence
        {
            return $this->precedence;
        }

        public function filter(array $graph, DocumentPage $page): array
        {
            return ($this->filter)($graph);
        }
    });
    app()->tag([$name], DocumentMeta::GRAPH_FILTERS);
}

/**
 * Saves the item's working revision again with these fields over its own,
 * as $actor, and publishes it, through the real Operations.
 *
 * @param  array<string, mixed>  $fields
 */
function sdRepublish(Content $content, User $actor, array $fields): void
{
    $content->refresh();
    $current = ContentRevision::query()->findOrFail($content->working_revision_id);
    docSave($content, $actor, (array) $current->document, [...(array) $current->fields, ...$fields]);
    listsPublish($content, $actor);
}

/**
 * A category with its archive's address, under $parent when given.
 */
function sdCategory(string $name, ?Term $parent = null): Term
{
    $term = taxonomyTerm('category', $name, $parent?->id);
    app(PathProjection::class)->syncTerm($term);

    return $term;
}

/**
 * Makes the fixture theme that draws a breadcrumb trail the active one.
 */
function sdTrailTheme(): void
{
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/trail')]]);
    themeActivate('trail');
}

// Audio and Video card helpers (I-446)

/**
 * A file PHP's fileinfo reads as video/mp4: an MP4's opening box, with
 * nothing to play. The library judges a file by its content, so this is
 * enough for it.
 */
function avVideoFile(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.mp4';
    file_put_contents($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));

    return $path;
}

/**
 * A file fileinfo reads as audio/mpeg: an ID3 header and four silent frames.
 */
function avAudioFile(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.mp3';
    file_put_contents($path, "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x64".str_repeat("\x00", 413), 4));

    return $path;
}

/**
 * A WebVTT caption file with one cue, which fileinfo reads as text/vtt.
 */
function avCaptionsFile(string $text = 'The tide comes in.'): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.vtt';
    file_put_contents($path, "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\n{$text}\n");

    return $path;
}

/**
 * Uploads one of the files above through the real Operation, as a
 * duplicate if need be, so a test may upload the same bytes twice.
 */
function avUpload(User $actor, string $path, string $name): Media
{
    return mediaUpload($actor, $path, $name, allowDuplicate: true);
}

/**
 * A video card node for this file: the editor's defaults, a new node ID,
 * and these attributes over them.
 *
 * @param  array<string, mixed>  $attrs
 *
 * @return array<string, mixed>
 */
function avVideoNode(Media $video, array $attrs = []): array
{
    return ['type' => 'video', 'attrs' => [
        'id' => (string) Str::ulid(), 'mediaId' => $video->id, 'posterMediaId' => null, 'caption' => null,
        'videoWidth' => null, 'videoHeight' => null, 'loop' => false, 'tracks' => null, ...$attrs,
    ]];
}

/**
 * An audio card node for this file, as avVideoNode() makes a video's.
 *
 * @param  array<string, mixed>  $attrs
 *
 * @return array<string, mixed>
 */
function avAudioNode(Media $audio, array $attrs = []): array
{
    return ['type' => 'audio', 'attrs' => [
        'id' => (string) Str::ulid(), 'mediaId' => $audio->id, 'caption' => null, 'transcript' => null, ...$attrs,
    ]];
}

// AI's helpers (I-314)

/**
 * The fake AI provider, registered under AiProviders::TAG for this test, and
 * the same one again if a test asks twice.
 */
function aiFake(): FakeAiProvider
{
    if (app()->bound('test.ai.fake')) {
        $fake = app('test.ai.fake');

        return $fake instanceof FakeAiProvider ? $fake : throw new LogicException('test.ai.fake is taken.');
    }

    $fake = new FakeAiProvider;
    app()->instance('test.ai.fake', $fake);
    app()->tag(['test.ai.fake'], AiProviders::TAG);

    return $fake;
}

/**
 * A task for tests, registered under AiTasks::TAG; once per key in a test.
 *
 * @param  list<AiCapability>  $capabilities
 */
function aiTask(string $key = 'fixture', array $capabilities = [AiCapability::Text], AiSubjectType $subject = AiSubjectType::Content, ?string $hash = null): FixtureTask
{
    $task = new FixtureTask($key, $capabilities, $subject, $hash);
    app()->instance("test.ai.task.{$key}", $task);
    app()->tag(["test.ai.task.{$key}"], AiTasks::TAG);

    // The group is built at boot; its model settings for this task need it built again.
    app(SettingsRegistry::class)->replace(app(AiSettings::class)->group());

    return $task;
}

/**
 * Chooses a task's model and fallback as stored settings, without the check
 * a form's save makes, so a test may store a choice the check would refuse.
 */
function aiChoose(string $task, string $model, string $fallback = ''): void
{
    cmSettings([ModelChoices::modelKey($task) => $model, ModelChoices::fallbackKey($task) => $fallback], AiSettings::KEY);
}

// The AI providers' helpers (I-314)

/**
 * Stores an AI provider's key, encrypted, as Settings › AI would; null
 * removes it.
 */
function aiKey(string $setting, ?string $key): void
{
    $secrets = SettingsGroupRecord::query()->find(AiSettings::KEY)?->secrets ?? [];

    if ($key === null) {
        unset($secrets[$setting]);
    } else {
        $secrets[$setting] = $key;
    }

    DB::table('settings')->updateOrInsert(
        ['group_key' => AiSettings::KEY],
        ['secrets' => $secrets === [] ? null : Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR))],
    );
}

/**
 * Sets a server's variable, as the host would; null puts back the empty
 * value phpunit.xml gives it. Call it with null in a finally block.
 */
function aiServerKey(string $variable, ?string $value): void
{
    putenv("{$variable}=".($value ?? ''));
    $_ENV[$variable] = $value ?? '';
    $_SERVER[$variable] = $value ?? '';
}

/**
 * A small request to one model: a system message, the material fenced as
 * the user's, an answer of 200 tokens at most, 30 seconds.
 *
 * @param  array<string, mixed>|null  $schema
 * @param  list<AiImage>  $images
 */
function aiRequest(string $model, ?array $schema = null, array $images = []): AiRequest
{
    return new AiRequest($model, [
        AiMessage::system('Rewrite the text more briefly.'),
        AiMessage::user('<input name="text">A rather long sentence about the ferry.</input>'),
    ], 200, 30, $images, $schema);
}

/**
 * An AI service's answer in its own wire form, for Http::fake(): Anthropic's
 * messages, OpenAI's responses, or the chat completions DeepSeek and
 * OpenRouter send. Input counts tokens neither read from nor written to the
 * cache; output counts every token written, reasoning included, as the
 * services count it.
 *
 * @param  array{text?: string, model?: string, input?: int, output?: int, cacheRead?: int, cacheWrite?: int, reasoning?: int, finish?: string}  $reply
 *
 * @return array<string, mixed>
 */
function aiReply(string $provider, array $reply = []): array
{
    $text = $reply['text'] ?? 'Shorter.';
    $model = $reply['model'] ?? 'answering-model';
    $input = $reply['input'] ?? 100;
    $output = $reply['output'] ?? 20;
    $cacheRead = $reply['cacheRead'] ?? 0;
    $cacheWrite = $reply['cacheWrite'] ?? 0;
    $reasoning = $reply['reasoning'] ?? 0;

    return match ($provider) {
        'anthropic' => [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $reply['finish'] ?? 'end_turn',
            'usage' => [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_read_input_tokens' => $cacheRead,
                'cache_creation_input_tokens' => $cacheWrite,
                'output_tokens_details' => ['thinking_tokens' => $reasoning],
            ],
        ],
        'openai' => [
            'id' => 'resp_test',
            'object' => 'response',
            'status' => 'completed',
            'model' => $model,
            'output' => [['type' => 'message', 'id' => 'msg_test', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
            'usage' => [
                'input_tokens' => $input + $cacheRead + $cacheWrite,
                'input_tokens_details' => ['cached_tokens' => $cacheRead, 'cache_write_tokens' => $cacheWrite],
                'output_tokens' => $output,
                'output_tokens_details' => ['reasoning_tokens' => $reasoning],
            ],
        ],
        default => [
            'id' => 'chat_test',
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => $reply['finish'] ?? 'stop']],
            'usage' => [
                'prompt_tokens' => $input + $cacheRead + $cacheWrite,
                'completion_tokens' => $output,
                ...($provider === 'deepseek'
                    ? ['prompt_cache_hit_tokens' => $cacheRead]
                    : ['prompt_tokens_details' => ['cached_tokens' => $cacheRead, 'cache_write_tokens' => $cacheWrite]]),
                'completion_tokens_details' => ['reasoning_tokens' => $reasoning],
            ],
        ],
    };
}

// Asking and applying's helpers (I-317)

/**
 * The fake provider and a fixture task with its model chosen, ready to be
 * asked: fake:fake-text, and a fallback when one is given.
 */
function aiReady(string $task = 'fixture', AiSubjectType $subject = AiSubjectType::Content, string $fallback = ''): FakeAiProvider
{
    $fake = aiFake();
    aiTask($task, [AiCapability::Text], $subject);
    aiChoose($task, 'fake:fake-text', $fallback);

    return $fake;
}

/**
 * A model's plain answer, as the fake gives it: 1,000 tokens in and 50 out,
 * which fake-text prices at 1,100 micro-dollars.
 */
function aiText(string $text, int $input = 1_000, int $output = 50): AiResult
{
    return new AiResult('fake-text-2026-11', new AiUsage($input, $output), 800, text: $text);
}

/**
 * A draft whose document is these paragraphs, through the real Operations.
 */
function aiDraft(User $author, string ...$paragraphs): Content
{
    $item = lifecycleDraft($author, 'Harbour news');

    return docSave($item, $author, docWith(...array_map(fn (string $text): array => docText($text), $paragraphs)))->refresh();
}

/**
 * Asks, as this person, for the task about the paragraph at $path in the
 * item's working revision: the whole of it, unless the options say
 * otherwise. The JSON answer.
 *
 * @param  array<string, string|int>  $options
 */
function aiAsk(User $person, Content $item, string $path = '0', array $options = [], string $task = 'fixture'): TestResponse
{
    $block = TextBlocks::at((array) ContentRevision::query()->findOrFail($item->working_revision_id)->document, $path);
    $length = $block === null ? 0 : TextBlocks::length(TextBlocks::text($block));

    return test()->actingAs($person)->postJson(route('ai.requests.store'), [
        'task' => $task,
        'subject_type' => 'content',
        'subject_id' => $item->id,
        'revision_id' => $item->working_revision_id,
        'target' => $path,
        'options' => array_replace(['from' => 0, 'to' => $length], $options),
    ]);
}

/**
 * What the page collects for a request, as this person.
 */
function aiCollect(User $person, string $id): TestResponse
{
    return test()->actingAs($person)->getJson(route('ai.requests.show', $id));
}

// AI activity's helpers (I-318)

/**
 * One AI request, recorded as the job that asked records it: through
 * RecordAiActivity, for $person. $changes name RecordAiActivityInput's
 * parameters. Returns the row's ID.
 *
 * @param  array<string, mixed>  $changes
 */
function actvRow(User $person, array $changes = []): string
{
    return app(RecordAiActivity::class)->execute(new RecordAiActivityInput(...array_replace([
        'id' => strtolower((string) Str::ulid()),
        'task' => 'tidy',
        'prompt' => new AiPrompt('tidy', 1, 'Shorten the text.', AiPrompt::hashOf('Shorten the text.')),
        'subjectType' => AiSubjectType::Content,
        'subjectId' => strtolower((string) Str::ulid()),
        'outcome' => AiOutcome::Suggested,
        'provider' => 'fake',
        'model' => 'fake-text',
        'usage' => new AiUsage(input: 1_200, output: 80),
        'costMicrounits' => 1_400,
        'latencyMs' => 2_300,
        'variant' => 'shorter',
        'actorId' => (string) $person->id,
    ], $changes)));
}

/**
 * Rows written straight into ai_activity, only so a plan looks as it does on
 * a large record: $count requests by $person for $task, a minute apart, going
 * back from now, numbered on from the last filler. The table is analysed after.
 */
function actvFiller(User $person, int $count, string $task = 'tidy'): void
{
    DB::statement(
        'INSERT INTO ai_activity (id, user_id, task, prompt_key, prompt_version, prompt_hash, subject_type, subject_id, outcome, created_at) '
        ."SELECT 'f' || lpad((n.done + g)::text, 25, '0'), ?, ?, ?, 1, repeat('a', 64), 'content', 'c' || lpad((n.done + g)::text, 25, '0'), 'suggested', now() - (n.done + g) * interval '1 minute' "
        ."FROM generate_series(1, ?) g, (SELECT count(*) AS done FROM ai_activity WHERE id LIKE 'f%') n",
        [$person->id, $task, $task, $count],
    );
    DB::statement('ANALYZE ai_activity');
}

// Admin shell helpers

/**
 * Waits until at least one element matches $selector and none of those has an
 * animation or transition running, so axe and the target-size rule read the
 * final layout of a menu, a drawer or a sidebar that was opening or closing.
 * It never asks about a subtree: a spinner inside would animate for ever.
 */
function shellSettled(mixed $page, string $selector): mixed
{
    $quoted = json_encode($selector, JSON_THROW_ON_ERROR);

    // Through cmWaitUntil(), whose answer is checked to be its own.
    return cmWaitUntil($page, "Array.from(document.querySelectorAll({$quoted})).length > 0 && Array.from(document.querySelectorAll({$quoted})).every((element) => element.getAnimations().length === 0)");
}

// Settings › AI's helpers (I-1308)

/**
 * The first of Settings › AI's sections of this kind, and of this key when
 * one is given, from the screen's data or the page's props.
 *
 * @param  array<string, mixed>  $data
 *
 * @return array<string, mixed>
 */
function aisSection(array $data, string $kind, ?string $key = null): array
{
    foreach ($data['sections'] ?? [] as $section) {
        if ($section['kind'] === $kind && ($key === null || $section['key'] === $key)) {
            return $section;
        }
    }

    throw new LogicException("Settings › AI has no {$kind} section".($key === null ? '.' : " [{$key}]."));
}

/**
 * One task's row from Settings › AI's Models section.
 *
 * @param  array<string, mixed>  $data
 *
 * @return array<string, mixed>
 */
function aisTask(array $data, string $key): array
{
    foreach (aisSection($data, 'models')['tasks'] as $task) {
        if ($task['key'] === $key) {
            return $task;
        }
    }

    throw new LogicException("Settings › AI has no task [{$key}].");
}

// The rewrite task's helpers (I-1309)

/**
 * The fake provider, with fake:fake-text chosen as the real rewrite task's
 * model, so the task is ready to ask. Never a fixture under its key (I-1668).
 */
function rewriteReady(): FakeAiProvider
{
    $fake = aiFake();
    aiChoose('rewrite', 'fake:fake-text');

    return $fake;
}

// The alt-text task's helpers (I-319)

/**
 * The fake provider, with the alt-text task's model chosen: fake:fake-vision,
 * which reads images, and a fallback when one is given.
 */
function altReady(string $fallback = ''): FakeAiProvider
{
    $fake = aiFake();
    aiChoose('alt-text', 'fake:fake-vision', $fallback);

    return $fake;
}

/**
 * A model's plain answer about an image, as the fake gives it.
 */
function altAnswer(string $text): AiResult
{
    return new AiResult('fake-vision-2026-11', new AiUsage(1_500, 30), 900, text: $text);
}

/**
 * An image uploaded as this person, its variants made on the sync queue.
 * mediaJpeg() draws one colour, so two in a test need two sizes.
 */
function altImage(User $actor, int $width = 1200, int $height = 800): Media
{
    return mediaUpload($actor, mediaJpeg($width, $height))->refresh();
}

/**
 * Asks, as this person, for the image's alt text, as its details do. The
 * JSON answer.
 */
function altAsk(User $person, Media $media): TestResponse
{
    return test()->actingAs($person)->postJson(route('ai.requests.store'), [
        'task' => 'alt-text',
        'subject_type' => 'media',
        'subject_id' => $media->id,
        'target' => 'alt',
        'options' => [],
    ]);
}

// The metadata task's helpers (I-1313)

/**
 * Five proposals the metadata task keeps, in its order.
 *
 * @return array<string, string>
 */
function mdValues(): array
{
    return [
        'seo_title' => 'Bonny Island ferry fares rise 15% from 1 October',
        'meta_description' => 'Crossings to Bonny Island cost 15% more from 1 October. Here is what changes for daily riders.',
        'excerpt' => 'Ferry fares to Bonny Island rise by 15% from 1 October.',
        'social_title' => 'Your Bonny Island ferry costs more from October',
        'social_description' => 'Fares rise 15% from 1 October. Here is what changes for daily riders.',
    ];
}

/**
 * The metadata task's answer as the fake gives it, as data: mdValues() with
 * these over them, 1,000 tokens in and 120 out.
 *
 * @param  array<string, mixed>  $values
 */
function mdAnswer(array $values = []): AiResult
{
    return new AiResult('fake-text-2026-11', new AiUsage(1_000, 120), 900, data: array_replace(mdValues(), $values));
}

/**
 * The fake provider, with fake:fake-text chosen for the metadata task.
 */
function mdReady(): FakeAiProvider
{
    $fake = aiFake();
    aiChoose('metadata', 'fake:fake-text');

    return $fake;
}

/**
 * Asks, as this person, for metadata about the item's working revision, as
 * the editor's buttons do: no target. The JSON answer.
 */
function mdAsk(User $person, Content $item): TestResponse
{
    return test()->actingAs($person)->postJson(route('ai.requests.store'), [
        'task' => 'metadata',
        'subject_type' => 'content',
        'subject_id' => $item->id,
        'revision_id' => $item->refresh()->working_revision_id,
    ]);
}

// Content types helpers (I-411)

/**
 * A type's definition as a file or an Operation takes it: a public Film
 * with posts' features and categories, unless told otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function ctDefinition(array $overrides = []): TypeDefinition
{
    return TypeDefinition::fromArray(array_replace([
        'key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'film',
        'public' => true, 'archive' => true, 'searchable' => true,
        'taxonomies' => ['category'], 'supports' => ['seo', 'excerpt', 'featured_image'],
        'comments' => 'closed', 'pattern' => null,
    ], $overrides));
}

/**
 * An administrator, with the roles seeded.
 */
function ctAdmin(): User
{
    test()->seed(RoleSeeder::class);

    return User::factory()->create()->assignRole('administrator');
}

/**
 * A type made through CreateType: as this person, or as the operator when null.
 *
 * @param  array<string, mixed>  $overrides
 *
 * @return array{key: string}
 */
function ctCreate(?User $actor, array $overrides = []): array
{
    $run = fn (): array => app(CreateType::class)->execute(new CreateTypeInput(ctDefinition($overrides), $actor === null ? null : (string) $actor->id));

    return $actor === null ? scElevated($run) : $run();
}

/**
 * A type's row written straight to the table, as another process's change
 * would leave it, with the cache store's copy put as that change puts it.
 * This process's registry hasn't looked: refreshStored() makes it.
 */
function ctStoreRow(TypeDefinition $type): void
{
    $definition = $type->settled()->toArray();
    unset($definition['key']);

    DB::table(StoredTypes::TABLE)->insert([
        'key' => $type->key,
        'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(StoredTypes::class)->remember();
}

/**
 * A teme-types.json file's contents, version 1, holding these types.
 *
 * @param  list<mixed>  $types
 * @param  array<string, mixed>  $extra
 *
 * @return array<string, mixed>
 */
function ctFile(array $types, array $extra = []): array
{
    return array_replace(['format' => 'teme-types', 'version' => 1, 'types' => $types, 'fields' => [], 'taxonomies' => [], 'grants' => []], $extra);
}

// Listings across types helpers (I-3298)

/**
 * Films and Recipes, made by the operator as the types file's fixture has
 * them (tests/Fixtures/Types/teme-types.json): Films public with an
 * archive, filed under categories, at /films/{slug}/; Recipes public with no
 * archive, filed under tags, at /recipes/{slug}/.
 */
function ltTypes(): void
{
    ctCreate(null);
    ctCreate(null, ['key' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes', 'segment' => 'recipes', 'icon' => 'cooking-pot', 'archive' => false, 'taxonomies' => ['tag'], 'supports' => ['seo', 'excerpt'], 'comments' => 'open']);
}

/**
 * Documentaries, a third public type with an archive filed under
 * categories, for the tests that compare three types with one.
 */
function ltDocumentaries(): void
{
    ctCreate(null, ['key' => 'documentary', 'singular' => 'Documentary', 'plural' => 'Documentaries', 'segment' => 'documentaries', 'icon' => 'clapperboard']);
}

/**
 * A published item of any type, through the real Operations: one paragraph,
 * filed under these terms in this order.
 *
 * @param  list<Term>  $terms
 */
function ltItem(User $author, string $type, string $title, array $terms = []): Content
{
    $content = lifecycleDraft($author, $title, $type);
    docSave($content, $author, docWith(docText()), [], array_map(fn (Term $term): string => (string) $term->id, $terms));
    listsPublish($content, $author);

    return $content->refresh();
}

/**
 * The titles of these cards, in their order.
 *
 * @param  list<ListingItem>  $items
 *
 * @return list<string>
 */
function ltTitles(array $items): array
{
    return array_map(fn (ListingItem $item): string => $item->title, $items);
}

// Type archive pages helpers (I-3304)

/**
 * Moves type archive pages through ChangeAddresses, as Settings › Addresses
 * sends them, every other address kept.
 *
 * @param  array<string, string>  $archives  type key => one word
 *
 * @return array{recompute: bool, actorId: string}
 */
function archMove(User $actor, array $archives): array
{
    return app(ChangeAddresses::class)->execute(new ChangeAddressesInput(
        patterns: [],
        bases: [],
        authorBase: null,
        frontShows: null,
        frontPageId: null,
        postsPageId: null,
        actorId: (string) $actor->id,
        archives: $archives,
    ));
}

/**
 * The card titles a public page lists, in their order.
 *
 * @return list<string>
 */
function archTitles(string $uri): array
{
    $titles = [];

    foreach (headDom($uri)->query('//*[@class="card-title"]') ?: [] as $node) {
        $titles[] = trim((string) $node->textContent);
    }

    return $titles;
}

/**
 * A page called Reels, published by $admin, then Reels, a public type with
 * an archive made by the operator: the page holds /reels/, the address the
 * archive page would start at.
 */
function archReelsAfterPage(User $admin): Content
{
    $page = addrPublished($admin, 'Reels', 'page');
    ctCreate(null, ['key' => 'reel', 'singular' => 'Reel', 'plural' => 'Reels', 'segment' => 'reels', 'icon' => 'film']);

    return $page;
}

/**
 * Photos, then Videos, two public types with an archive made by the
 * operator, each with the address /media/{slug}/: both archive pages start
 * at media.
 */
function archMediaTypes(): void
{
    ctCreate(null, ['key' => 'photo', 'singular' => 'Photo', 'plural' => 'Photos', 'segment' => 'photos', 'icon' => 'image', 'pattern' => '/media/{slug}/']);
    ctCreate(null, ['key' => 'video', 'singular' => 'Video', 'plural' => 'Videos', 'segment' => 'videos', 'icon' => 'video', 'pattern' => '/media/{slug}/']);
}

// Type builder helpers (I-3302)

/**
 * The site the type builder's drawing shows: Films made here with three
 * items, one published and one in the trash; Recipes made here with none;
 * Events saved but refused, for a taxonomy nobody registered. Made on 2, 3
 * and 4 November 2026, in that order.
 */
function tbSite(User $admin): void
{
    ctCreate($admin);
    ctCreate($admin, ['key' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes', 'segment' => 'recipes', 'icon' => 'chef-hat', 'archive' => false, 'taxonomies' => ['category', 'tag'], 'comments' => 'open']);
    ctStoreRow(ctDefinition(['key' => 'event', 'singular' => 'Event', 'plural' => 'Events', 'segment' => 'events', 'icon' => 'calendar', 'taxonomies' => ['venue'], 'supports' => ['seo', 'excerpt'], 'comments' => null]));

    foreach (['film' => '2026-11-02 10:00:00', 'recipe' => '2026-11-03 10:00:00', 'event' => '2026-11-04 10:00:00'] as $key => $made) {
        DB::table(StoredTypes::TABLE)->where('key', $key)->update(['created_at' => $made]);
    }

    app(StoredTypes::class)->remember();
    app(ContentTypeRegistry::class)->refreshStored();

    lifecycleDraft($admin, 'Arrival', 'film');
    addrPublished($admin, 'The long walk', 'film');
    pageTrash(lifecycleDraft($admin, 'Heat', 'film'), $admin);
}

// Per-type permissions helpers (I-413)

/**
 * What each of the four roles holds of a type's own permissions, by name,
 * sorted, read from the database rather than Spatie's cached list.
 *
 * @return array<string, list<string>>
 */
function tpHeld(string $key): array
{
    $held = [];

    foreach (['administrator', 'editor', 'author', 'contributor'] as $role) {
        $held[$role] = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)
            ->where('permissions.name', 'like', $key.'.%')
            ->pluck('permissions.name')
            ->sort()
            ->values()
            ->all();
    }

    return $held;
}

/**
 * What tpHeld() reads for a type given its own permissions on a site with
 * RoleSeeder's grants: each role what it holds of content.* (RoleSeeder.php).
 *
 * @return array<string, list<string>>
 */
function tpStarting(string $key = 'film'): array
{
    return [
        'administrator' => ["{$key}.create", "{$key}.delete.any", "{$key}.delete.own", "{$key}.edit.any", "{$key}.edit.own", "{$key}.publish"],
        'editor' => ["{$key}.create", "{$key}.delete.any", "{$key}.edit.any", "{$key}.edit.own", "{$key}.publish"],
        'author' => ["{$key}.create", "{$key}.delete.own", "{$key}.edit.own", "{$key}.publish"],
        'contributor' => ["{$key}.create", "{$key}.delete.own", "{$key}.edit.own"],
    ];
}

// Pages' own permissions helpers (I-3313)

/**
 * A page $author wrote before pages had permissions of their own, as an
 * upgraded site has them: an editor's page, given to $author through
 * UpdateContentDetails, published when asked.
 */
function pgAuthorsPage(User $author, string $title = 'About us', bool $published = false): Content
{
    $editor = User::factory()->create()->assignRole('editor');
    $page = ($published ? addrPublished($editor, $title, 'page') : lifecycleDraft($editor, $title, 'page'))->refresh();

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($page->id, $page->working_revision_id, (string) $editor->id, authorId: (int) $author->id));

    return $page->refresh();
}

// Taxonomy builder helpers (I-3319)

/**
 * A taxonomy's definition as a file or an Operation takes it: Genres,
 * nested and public at /genre/, used by no code type, unless told otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function txDefinition(array $overrides = []): TaxonomyDefinition
{
    return TaxonomyDefinition::fromArray(array_replace([
        'key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres', 'icon' => 'folder',
        'hierarchical' => true, 'public' => true, 'base' => 'genre', 'code_types' => [],
    ], $overrides));
}

/**
 * A taxonomy's row written straight to the table, as another process's
 * change would leave it, with the cache store's copy put as that change
 * puts it. This process's registries haven't looked: refreshStored() makes them.
 */
function txStoreRow(TaxonomyDefinition $taxonomy): void
{
    $definition = $taxonomy->settled()->toArray();
    unset($definition['key']);

    DB::table(StoredTaxonomies::TABLE)->insert([
        'key' => $taxonomy->key,
        'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(StoredTypes::class)->remember();
}

/**
 * A taxonomy made through CreateTaxonomy: as this person, or as the operator when null.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $types
 *
 * @return array{key: string}
 */
function txCreate(?User $actor, array $overrides = [], array $types = []): array
{
    $run = fn (): array => app(CreateTaxonomy::class)->execute(new CreateTaxonomyInput(txDefinition($overrides), $types, $actor === null ? null : (string) $actor->id));

    return $actor === null ? scElevated($run) : $run();
}

/**
 * Genres changed through UpdateTaxonomy, as this person.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $types
 *
 * @return array{key: string, changed: bool}
 */
function txUpdate(User $actor, array $overrides, array $types): array
{
    return app(UpdateTaxonomy::class)->execute(new UpdateTaxonomyInput(txDefinition($overrides), $types, (string) $actor->id));
}

// Update check helpers (I-?? draft)

/**
 * One item of GitHub's release list, as its REST API gives it.
 *
 * @param  array<string, mixed>  $overrides
 *
 * @return array<string, mixed>
 */
function updItem(string $version, array $overrides = []): array
{
    return [
        'tag_name' => 'v'.$version,
        'name' => 'Teme '.$version,
        'draft' => false,
        'prerelease' => str_contains($version, '-'),
        'published_at' => '2026-11-10T09:00:00Z',
        'html_url' => 'https://github.com/Krafty-Sprouts-Media-LLC/Teme/releases/tag/v'.$version,
        'body' => "Notes for {$version}.\n\n<!-- teme-release {\"format\":1,\"security\":false,\"maintenance\":false} -->\n",
        ...$overrides,
    ];
}

/**
 * GitHub's release list answering with $response, every other address refused.
 */
function updFake(mixed $response): void
{
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/repos/Krafty-Sprouts-Media-LLC/Teme/releases*' => $response]);
}

/** The operator acting, as at the server's shell. */
function updOperator(): void
{
    app()->instance(Elevation::class, new OperatorElevation);
}

/** A deploy_runs row: $version deployed, tagged, $minutesAgo ago, with the report's needs_maintenance. */
function updDeployed(string $version, int $minutesAgo = 60, array $needsMaintenance = [], int $exitCode = 0): void
{
    DB::table('deploy_runs')->insert([
        'id' => strtolower((string) Str::ulid()), 'version' => $version, 'revision' => null, 'tagged' => true,
        'outcome' => $exitCode === 0 ? 'deployed' : 'migration-failed', 'exit_code' => $exitCode, 'sentence' => 'Deployed.', 'backup_run_id' => null,
        'report' => json_encode(['migrations' => ['ran' => [], 'needs_maintenance' => $needsMaintenance]], JSON_THROW_ON_ERROR),
        'started_at' => now()->subMinutes($minutesAgo + 1), 'finished_at' => now()->subMinutes($minutesAgo),
    ]);
}

/**
 * A site running $version, tagged, with $releases in teme_releases and a deploy of $version recorded.
 *
 * @param  list<string|array{0: string, 1: array<string, mixed>}>  $releases  a version, or a version and updItem()'s overrides
 */
function updSite(string $version, array $releases): void
{
    config(['teme.version' => $version, 'teme.tagged' => true, 'teme.updates.channel' => null, 'teme.updates.check' => true]);
    updOperator();
    updFake(Http::response(array_map(fn (string|array $release): array => is_array($release) ? updItem($release[0], $release[1]) : updItem($release), $releases), 200));
    app(CheckForUpdates::class)->run();
    updDeployed($version);
    app()->forgetInstance(Elevation::class);
}

/**
 * A release of the list that GitHub marks as needing maintenance mode, for updSite().
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function updMarked(string $version): array
{
    return [$version, ['body' => "Notes for {$version}.\n\n<!-- teme-release {\"format\":1,\"security\":false,\"maintenance\":true} -->\n"]];
}

/** An administrator whose password counts as confirmed, so no Operation asks for it. */
function updAdmin(): User
{
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new OperatorElevation);

    return $admin;
}

// Hooks helpers (slice 5.1a)

/**
 * A plugin's own Hooks, bound to its name, what it was granted, the hooks it
 * declared (null for any) and its quarantined subscriptions.
 *
 * @param  list<string>  $capabilities
 * @param  list<string>|null  $declared
 * @param  list<string>  $quarantined
 */
function hkSubscriber(string $owner = 'acme/one', array $capabilities = ['content.read'], ?array $declared = null, array $quarantined = []): OwnedHooks
{
    return app(HookDispatcher::class)->subscriber($owner, $capabilities, $declared, $quarantined);
}

/**
 * Records every listener's fault in place of reporting it, for hkFaults().
 */
function hkRecordFaults(): void
{
    app()->instance(ListenerFaults::class, new class implements ListenerFaults
    {
        /** @var list<array{owner: string, id: string, hook: string, message: string}> */
        public array $faults = [];

        public function record(string $owner, string $id, string $hook, Throwable $failure): void
        {
            $this->faults[] = ['owner' => $owner, 'id' => $id, 'hook' => $hook, 'message' => $failure->getMessage()];
        }
    });
}

/**
 * The faults recorded since hkRecordFaults().
 *
 * @return list<array{owner: string, id: string, hook: string, message: string}>
 */
function hkFaults(): array
{
    /** @var object{faults: list<array{owner: string, id: string, hook: string, message: string}>} $recorder */
    $recorder = app(ListenerFaults::class);

    return $recorder->faults;
}

/**
 * The transaction level a test runs at, outside any Operation: what a
 * listener delivered after the outermost commit reads.
 */
function hkBaseLevel(): int
{
    return DB::transactionLevel();
}

/**
 * Subscribes core to each hook, and returns the hook objects heard, in order.
 */
function hkHear(string ...$hooks): ArrayObject
{
    $heard = new ArrayObject;
    $core = app(HookDispatcher::class)->subscriber('core');

    foreach ($hooks as $hook) {
        $core->on($hook, function (object $payload) use ($heard): void {
            $heard[] = $payload;
        });
    }

    return $heard;
}

/**
 * The public properties, walked recursively, whose values aren't plain: not
 * null, a scalar, a list of strings, an object of class exactly
 * DateTimeImmutable, or an ItemRef or MediaRef, whose own properties are walked.
 *
 * @return list<string>
 */
function hkOffenders(object $value, string $prefix = ''): array
{
    $offenders = [];

    foreach (get_object_vars($value) as $name => $property) {
        $path = $prefix.$name;

        if ($property instanceof ItemRef || $property instanceof MediaRef) {
            array_push($offenders, ...hkOffenders($property, $path.'->'));

            continue;
        }

        $plain = $property === null
            || is_scalar($property)
            || (is_array($property) && array_is_list($property) && array_filter($property, fn (mixed $item): bool => ! is_string($item)) === [])
            || (is_object($property) && $property::class === DateTimeImmutable::class);

        if (! $plain) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * A real PNG of the given size, written to a temporary file.
 */
function hkPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 80, 200));
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.png';
    imagepng($image, $path);

    return $path;
}
