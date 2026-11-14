<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Site\Movies;

uses(RefreshDatabase::class);

/*
 * A scratch copy of the default theme, code viewdata, whose cards print two
 * of their item's fields and whose archive names a term's parent and
 * children: the default theme draws neither, and its own output stays as
 * it is. Built at the test's start and removed at its end, with its assets'
 * link and the media files served for it.
 */
beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->served = [];
    $this->themeDir = storage_path('framework/testing/view-data/viewdata');
    $this->assetLink = public_path('themes/viewdata');

    File::deleteDirectory($this->themeDir);
    File::copyDirectory(base_path('themes/default'), $this->themeDir);
    $manifest = json_decode((string) file_get_contents($this->themeDir.'/teme-theme.json'), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($this->themeDir.'/teme-theme.json', json_encode([...$manifest, 'code' => 'viewdata', 'name' => 'View data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $card = $this->themeDir.'/views/partials/card.blade.php';
    file_put_contents($card, str_replace("    </div>\n</article>", <<<'BLADE'
            @foreach (['release_date', 'rating'] as $handle)
                @if ($item->field($handle) !== null && ! $item->field($handle)->empty)
                    <p class="meta" data-test="card-field">{{ $item->field($handle)->label }}: {{ $item->field($handle) }}</p>
                @endif
            @endforeach
        </div>
    </article>
    BLADE, (string) file_get_contents($card)));

    $archive = $this->themeDir.'/views/templates/archive.blade.php';
    file_put_contents($archive, str_replace("        </header>\n", <<<'BLADE'
                @if ($page->subject?->parent !== null || ($page->subject?->children ?? []) !== [])
                    <nav aria-label="{{ $page->subject->title }}" data-test="term-family">
                        @if ($page->subject->parent !== null)
                            <p class="meta">↑ <a href="{{ $page->subject->parent->url }}">{{ $page->subject->parent->name }}</a></p>
                        @endif
                        <ul>
                            @foreach ($page->subject->children as $child)
                                <li><a href="{{ $child->url }}">{{ $child->name }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
            </header>

    BLADE, (string) file_get_contents($archive)));

    File::ensureDirectoryExists(public_path('themes'));

    vdUnlink($this->assetLink);
    File::link($this->themeDir.'/public', $this->assetLink);

    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [$this->themeDir]]);
    themeActivate('viewdata');
    $this->admin = ctAdmin();
    lscrTimezone('Africa/Lagos');
    siteSetLanguage($this->admin, 'en-GB');
});

afterEach(function (): void {
    foreach ($this->served as $id) {
        Storage::disk('public')->deleteDirectory($id);
    }

    vdUnlink($this->assetLink);
    File::deleteDirectory(dirname($this->themeDir));
});

/**
 * Removes the link File::link() made here, and only the link: a symbolic
 * link, or on Windows the junction it makes instead, which is_link() does
 * not see and only rmdir() removes (as LinkThemes does).
 */
function vdUnlink(string $link): void
{
    $junction = windows_os() && is_dir($link) && realpath($link) !== realpath(dirname($link)).DIRECTORY_SEPARATOR.basename($link);

    if (is_link($link) || $junction) {
        windows_os() ? rmdir($link) : unlink($link);
    }
}

/**
 * An uploaded image whose files are copied to where the test server finds
 * them (the fake media disk is not served), removed afterwards.
 */
function vdServedImage(int $width, int $height): string
{
    $media = mediaUpload(test()->admin, mediaJpeg($width, $height), 'Poster.jpg', allowDuplicate: true);

    // The public disk is served from public/storage, the link `storage:link` makes.
    if (! file_exists(public_path('storage'))) {
        File::link(storage_path('app/public'), public_path('storage'));
    }

    foreach (Storage::disk('media')->allFiles($media->id) as $path) {
        Storage::disk('public')->put($path, (string) Storage::disk('media')->get($path));
    }

    test()->served = [...test()->served, $media->id];

    return $media->id;
}

/**
 * A term made through CreateTerm, so it has its address.
 */
function vdTerm(string $name, ?Term $parent = null): Term
{
    return app(CreateTerm::class)->execute(new SaveTermInput('category', $name, null, $parent?->id, null, (string) test()->admin->id));
}

it('saves the pictures the owner looks at: cards with their fields, and a term with its parent and children', function (): void {
    Movies::type(supports: ['featured_image']);

    foreach (['Arrival' => ['2016-11-11', 'pg-13'], 'Dune' => ['2021-10-22', 'pg-13'], 'Sicario' => ['2015-10-02', 'r']] as $title => [$release, $rating]) {
        $poster = vdServedImage(1200, 800);
        Movies::movie($this->admin, $title, [
            Movies::RELEASE => $release, Movies::RATING => $rating,
            Movies::POSTER => Movies::image($poster), BuiltInFields::FEATURED_IMAGE => Movies::image($poster, "{$title}’s poster"),
        ]);
    }

    $world = vdTerm('World');
    $africa = vdTerm('Africa', $world);
    $nigeria = vdTerm('Nigeria', $africa);
    $ghana = vdTerm('Ghana', $africa);
    vdTerm('Togo', $africa);
    pageArticle($this->admin, 'Across the continent', excerpt: 'Notes from many places.', categories: [$africa]);
    pageArticle($this->admin, 'Lagos traffic', excerpt: 'A morning on the bridge.', categories: [$nigeria]);
    pageArticle($this->admin, 'Accra markets', excerpt: 'Before the heat.', categories: [$ghana]);

    $page = visit('/movies/')->resize(1280, 900);
    $page->assertSee('Release date: 11 November 2016')
        ->assertSee('Rating: PG-13')
        ->assertScript("[...document.querySelectorAll('.card-media img')].every((img) => img.complete && img.naturalWidth > 0)");
    $page->screenshot(true, 'view-data-cards');

    cmNavigate($page, '/category/world/africa/');
    $page->assertSee('World')
        ->assertScript("[...document.querySelectorAll('[data-test=\"term-family\"] li')].map((li) => li.textContent.trim()).join(',') === 'Ghana,Nigeria'");
    $page->screenshot(true, 'view-data-term-family');
});
