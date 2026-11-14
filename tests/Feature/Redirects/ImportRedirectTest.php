<?php

declare(strict_types=1);

use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->importer = User::factory()->create()->assignRole('administrator');
    $this->item = addrPublished($this->importer, 'Harbour news');
    $this->file = mediaUpload($this->importer, mediaJpeg(), 'harbour.jpg');
});

/**
 * ImportRedirect with these fields, as $actor, or as the operator (at the
 * server's shell unless $shell is false).
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array{redirectId: string, outcome: string}
 */
function irdImport(array $fields, ?User $actor, bool $shell = true): array
{
    $input = new ImportRedirectInput(...[...$fields, 'actorId' => $actor === null ? null : (string) $actor->id]);
    $run = fn (): array => app(ImportRedirect::class)->execute($input);

    return $actor === null && $shell ? scElevated($run) : $run();
}

it('adds an imported redirect to an item, a file or an address, and logs nothing (I-244)', function (string $to, string $kind): void {
    $fields = match ($to) {
        'an item' => ['from' => '/2024/05/harbour/photo/', 'contentId' => $this->item->id],
        'a file' => ['from' => '/photo/', 'mediaId' => $this->file->id],
        default => ['from' => '/old-news/', 'to' => '/harbour-news/'],
    };
    $logged = DB::table('security_events')->count();

    $result = irdImport($fields, $this->importer);
    $redirect = Redirect::query()->findOrFail($result['redirectId']);

    expect($result['outcome'])->toBe(ImportRedirect::CREATED)
        ->and([$redirect->origin, $redirect->enabled, $redirect->status, $redirect->target_kind, $redirect->created_by])->toBe(['import', true, 301, $kind, $this->importer->id])
        ->and(DB::table('security_events')->count())->toBe($logged);

    if ($kind === 'content') {
        expect(addrGet($fields['from'])->status())->toBe(301);
    }
})->with([['an item', 'content'], ['a file', 'media'], ['an address', 'content']]);

it('adds one as the operator at the server’s shell, with no person behind it', function (): void {
    $result = irdImport(['from' => '/photo/', 'contentId' => $this->item->id], null);

    expect(Redirect::query()->findOrFail($result['redirectId'])->created_by)->toBeNull();
});

it('keeps a redirect already at that address, a person’s or its own (I-297)', function (): void {
    rdSaved('/photo/', '/news/');
    $first = irdImport(['from' => '/2024/05/harbour/photo/', 'contentId' => $this->item->id], $this->importer);

    expect(irdImport(['from' => '/photo/', 'contentId' => $this->item->id], $this->importer)['outcome'])->toBe(ImportRedirect::KEPT)
        ->and(irdImport(['from' => '/2024/05/harbour/photo/', 'contentId' => $this->item->id], $this->importer))->toBe(['redirectId' => $first['redirectId'], 'outcome' => ImportRedirect::UNCHANGED])
        ->and(irdImport(['from' => '/2024/05/harbour/photo/', 'to' => '/news/'], $this->importer)['outcome'])->toBe(ImportRedirect::KEPT)
        ->and(Redirect::query()->where('source', 'photo')->value('target'))->toBe('/news/')
        ->and(Redirect::query()->count())->toBe(2);
});

it('moves its own redirect to a file on to the item, never a person’s (I-860)', function (): void {
    $own = irdImport(['from' => '/2024/05/harbour/photo/', 'mediaId' => $this->file->id], $this->importer);
    rdSaved('/photo/', file: $this->file->id);

    $moved = irdImport(['from' => '/2024/05/harbour/photo/', 'contentId' => $this->item->id, 'replacing' => $this->file->id], $this->importer);
    $kept = irdImport(['from' => '/photo/', 'contentId' => $this->item->id, 'replacing' => $this->file->id], $this->importer);

    expect($moved)->toBe(['redirectId' => $own['redirectId'], 'outcome' => ImportRedirect::UPDATED])
        ->and(Redirect::query()->findOrFail($own['redirectId'])->only(['target_kind', 'target', 'origin']))->toBe(['target_kind' => 'content', 'target' => $this->item->id, 'origin' => 'import'])
        ->and($kept['outcome'])->toBe(ImportRedirect::KEPT)
        ->and(Redirect::query()->where('source', 'photo')->value('target_kind'))->toBe('media');
});

it('lets the old site’s own rule replace a redirect the import derived, never a person’s', function (): void {
    $derived = irdImport(['from' => '/2024/05/harbour/photo/', 'mediaId' => $this->file->id], $this->importer);
    rdSaved('/menu/', '/news/');

    $rule = irdImport(['from' => '/2024/05/harbour/photo/', 'to' => '/harbour-news/', 'overImport' => true], $this->importer);
    $theirs = irdImport(['from' => '/menu/', 'to' => '/harbour-news/', 'overImport' => true], $this->importer);

    expect($rule)->toBe(['redirectId' => $derived['redirectId'], 'outcome' => ImportRedirect::UPDATED])
        ->and(Redirect::query()->findOrFail($derived['redirectId'])->only(['target_kind', 'target', 'origin']))->toBe(['target_kind' => 'content', 'target' => $this->item->id, 'origin' => 'import'])
        ->and($theirs['outcome'])->toBe(ImportRedirect::KEPT)
        ->and(Redirect::query()->where('source', 'menu')->value('target'))->toBe('/news/');
});

it('refuses what any redirect is refused, writing nothing', function (string $case): void {
    rdSaved('/a/', '/photo/');
    $from = $case === 'a chain' ? '/photo/' : '/admin/photo/';

    expect(fn (): array => irdImport(['from' => $from, 'contentId' => $this->item->id], $this->importer))
        ->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toHaveKey('source'))
        ->and(Redirect::query()->where('origin', 'import')->count())->toBe(0);
})->with(['a chain', 'an address the site itself answers']);

it('writes nothing when the actor may not import', function (string $who): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn (): array => irdImport(['from' => '/photo/', 'contentId' => $this->item->id], $who === 'an editor' ? $editor : null, shell: false))
        ->toThrow(AuthorizationException::class)
        ->and(Redirect::query()->count())->toBe(0);
})->with(['an editor', 'no one, away from the operator’s shell']);
