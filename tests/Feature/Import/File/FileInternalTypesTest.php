<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('local');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP]]);
    config(['teme.import.local_sources' => false]);
    Http::preventStrayRequests();
    Http::fake();
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The fixture file with WordPress's own records added, each as [id, type,
 * slug, status], beside one item of a custom type of the site's.
 *
 * @param  list<array{0: int, 1: string, 2: string, 3: string}>  $items
 */
function itFile(array $items): string
{
    $added = '';

    foreach ($items as [$id, $type, $slug, $status]) {
        $added .= "\n\t<item>\n\t\t<title><![CDATA[{$slug}]]></title>\n\t\t<link>https://old.example/{$slug}/</link>\n\t\t<dc:creator><![CDATA[harbour]]></dc:creator>\n"
            ."\t\t<content:encoded><![CDATA[{}]]></content:encoded>\n\t\t<excerpt:encoded><![CDATA[]]></excerpt:encoded>\n"
            ."\t\t<wp:post_id>{$id}</wp:post_id>\n\t\t<wp:post_date><![CDATA[2024-03-02 10:00:00]]></wp:post_date>\n\t\t<wp:post_date_gmt><![CDATA[2024-03-02 10:00:00]]></wp:post_date_gmt>\n"
            ."\t\t<wp:post_name><![CDATA[{$slug}]]></wp:post_name>\n\t\t<wp:status><![CDATA[{$status}]]></wp:status>\n\t\t<wp:post_parent>0</wp:post_parent>\n\t\t<wp:post_type><![CDATA[{$type}]]></wp:post_type>\n\t</item>";
    }

    return (string) preg_replace('~(\s*</channel>)~', $added.'$1', (string) file_get_contents(ExportFiles::path('companion-site.xml')), 1);
}

it('never lists, checks or brings WordPress’s own records, and lists a type of the site’s by its key', function (): void {
    $records = [
        [501, 'custom_css', 'pixwell-child', 'publish'],
        [502, 'wp_global_styles', 'wp-global-styles-pixwell', 'publish'],
        [503, 'wp_global_styles', 'wp-global-styles-pixwell-child', 'publish'],
        [504, 'wp_template', 'single', 'publish'],
        [505, 'wp_template_part', 'header', 'publish'],
        [506, 'wp_font_family', 'inter', 'publish'],
        [507, 'wp_font_face', 'inter-400', 'publish'],
        [508, 'oembed_cache', 'a1b2c3', 'publish'],
        [509, 'customize_changeset', 'c0ffee', 'trash'],
        [510, 'user_request', 'export-request', 'request-completed'],
        [511, 'post', 'unsaved', 'auto-draft'],
    ];
    $file = FileImports::ready('companion-site.xml', $this->admin, itFile([...$records, [520, 'film', 'the-harbour', 'publish']]));
    $stays = collect(app(ImportAnalysis::class)->read((string) $file->source_id)->toArray()['stays'])->filter(fn (array $stay): bool => str_starts_with($stay['key'], 'type.'));

    expect($stays->map(fn (array $stay): array => [$stay['wordpressKey'], $stay['label'], $stay['count']])->values()->all())->toBe([['film', 'film', 1]]);

    $run = FileImports::run($file, ['media' => 'links']);
    $ids = array_map(fn (array $record): string => (string) $record[0], $records);
    $messages = DB::table('import_messages')->where('run_id', $run->id)->pluck('message')->implode("\n");

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('import_map')->where('object_type', 'post')->whereIn('object_id', $ids)->count())->toBe(0)
        ->and(DB::table('contents')->whereIn('slug', ['pixwell-child', 'single', 'unsaved'])->count())->toBe(0)
        ->and($messages)->not->toContain('custom_css')
        ->and($messages)->not->toContain('wp_global_styles')
        ->and($messages)->not->toContain('/pixwell-child/')
        ->and($messages)->not->toContain('/unsaved/');
});
