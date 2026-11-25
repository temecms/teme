<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
    // Each of the old site's files has bytes of its own, so none is stored as another's.
    Http::fake(function (Request $request): ?PromiseInterface {
        $url = parse_url($request->url());
        $path = (string) ($url['path'] ?? '/');

        if (($url['host'] ?? '') !== FakeCompanion::HOST) {
            return null;
        }

        if (str_starts_with($path, '/wp-content/uploads/')) {
            return str_ends_with($path, '.pdf')
                ? Http::response("%PDF-1.4\n% {$path}\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 200, ['Content-Type' => 'application/pdf'])
                : Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']);
        }

        return $path === '/wp-json/teme/v1/redirects' ? Http::response(['api' => 1, 'limit' => 500, 'next' => null, 'items' => []]) : null;
    });
    importFake();
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The old site's files the import asked for, by path.
 *
 * @return list<string>
 */
function lanesFileRequests(): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/wp-content/uploads/'))
        ->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_PATH))
        ->values()
        ->all();
}

it('downloads every attachment once over three lanes, and none again in a second run (#701)', function (): void {
    $xml = (string) file_get_contents(ExportFiles::path('companion-site.xml'));
    preg_match('~\t<item>\s*<title><!\[CDATA\[Summer menu\]\]></title>.*?</item>\n~s', $xml, $menu);
    $more = implode('', array_map(
        fn (int $id): string => str_replace(['<wp:post_id>13</wp:post_id>', 'menu.pdf', '?p=13'], ["<wp:post_id>{$id}</wp:post_id>", "menu-{$id}.pdf", "?p={$id}"], $menu[0]),
        range(101, 105),
    ));
    $file = FileImports::ready('companion-site.xml', $this->admin, str_replace('</channel>', $more.'</channel>', $xml));

    $run = FileImports::run($file, [], ['media_lanes' => 3]);
    $first = lanesFileRequests();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($run->options['media_lanes'])->toBe(3)
        ->and(Media::query()->count())->toBe(7)
        ->and($first)->toHaveCount(count(array_unique($first)))
        ->and(array_filter($first, fn (string $path): bool => str_contains($path, '/menu-1')))->toHaveCount(5);

    FileImports::run($file, [], ['media_lanes' => 3]);

    expect(lanesFileRequests())->toBe($first);
});
