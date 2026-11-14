<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->list = '/admin/tools/redirects';
    $this->store = fn (array $form, ?User $actor = null) => $this->actingAs($actor ?? $this->editor)->from($this->list)->post('/admin/tools/redirects', $form);
    $this->save = fn (string $redirect, array $form, ?User $actor = null) => $this->actingAs($actor ?? $this->editor)->from($this->list)->put("/admin/tools/redirects/{$redirect}", $form);
});

it('adds a redirect to an item or a file, kept by its ID, and says where it sends readers (I-860)', function (): void {
    $fares = addrPublished($this->editor, 'Ferry fares');
    $file = mediaUpload($this->editor, mediaPdf(), 'Timetable.pdf');

    ($this->store)(['from' => '/old-fares', 'status' => 301, 'kind' => 'item', 'item' => $fares->id, 'to' => '/ignored/', 'note' => 'From the print edition'])
        ->assertRedirect($this->list)
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Redirect added. /old-fares now sends readers to “Ferry fares”.');

    ($this->store)(['from' => '/timetable', 'status' => 302, 'kind' => 'file', 'file' => $file->id])
        ->assertInertiaFlash('toast.message', 'Redirect added. /timetable now sends readers to the file '.$file->name.'.');

    expect(DB::table('redirects')->orderBy('source')->get(['source', 'status', 'target_kind', 'target', 'note', 'created_by'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['source' => 'old-fares', 'status' => 301, 'target_kind' => 'content', 'target' => $fares->id, 'note' => 'From the print edition', 'created_by' => $this->editor->id],
        ['source' => 'timetable', 'status' => 302, 'target_kind' => 'media', 'target' => $file->id, 'note' => '', 'created_by' => $this->editor->id],
    ]);
});

it('says what a new redirect answers, in its own words: another site, an address, a 410, or what it waits for (I-694, I-858)', function (array $form, string $words): void {
    addrPublished($this->editor, 'About us');

    ($this->store)($form)->assertRedirect($this->list)->assertInertiaFlash('toast.message', $words);
})->with([
    'another site' => [['from' => '/podcast', 'status' => 302, 'kind' => 'address', 'to' => 'https://podcasts.example.net/ledger'], 'Redirect added. /podcast now sends readers to https://podcasts.example.net/ledger.'],
    'an address on this site' => [['from' => '/old-news', 'status' => 301, 'kind' => 'address', 'to' => '/news/'], 'Redirect added. /old-news now sends readers to /news/.'],
    'a 410' => [['from' => '/withdrawn', 'status' => 410, 'kind' => 'item', 'item' => ''], 'Redirect added. /withdrawn now answers 410 (gone).'],
    'an address a live page answers' => [['from' => '/about-us', 'status' => 301, 'kind' => 'address', 'to' => '/news/'], 'Redirect added. Waiting: a page answers this address.'],
]);

it('refuses under the field the refusal concerns, and adds nothing', function (array $form, string $field, string $words): void {
    ($this->store)($form)->assertRedirect($this->list)->assertSessionHasErrors([$field => $words]);

    expect(DB::table('redirects')->count())->toBe(0);
})->with([
    'no address to redirect from' => [['from' => '', 'status' => 301, 'kind' => 'address', 'to' => '/news/'], 'source', 'Enter the address to redirect from, starting /.'],
    'the home page' => [['from' => '/', 'status' => 301, 'kind' => 'address', 'to' => '/news/'], 'source', 'The home page always answers, so a redirect from / would send nobody on. Enter another address.'],
    'no post or page chosen' => [['from' => '/old', 'status' => 301, 'kind' => 'item', 'item' => ''], 'item', 'Choose the post or page to send readers to.'],
    'no file chosen' => [['from' => '/old', 'status' => 301, 'kind' => 'file', 'file' => ''], 'file', 'Choose the file to send readers to.'],
]);

it('saves a redirect, and says what it does now, switched off too', function (): void {
    $id = rdSaved('/old-fares', '/news/');
    $off = rdSaved('/summer-guide', '/news/', on: false);

    ($this->save)($id, ['from' => '/old-fares', 'status' => 302, 'kind' => 'address', 'to' => '/news/ferries/', 'note' => 'Moved again'])
        ->assertRedirect($this->list)
        ->assertInertiaFlash('toast.message', 'Redirect saved. /old-fares now sends readers to /news/ferries/.');

    ($this->save)($off, ['from' => '/summer-guide', 'status' => 301, 'kind' => 'address', 'to' => '/news/'])
        ->assertInertiaFlash('toast.message', 'Redirect saved. It’s switched off, so it sends nobody on.');

    expect((array) DB::table('redirects')->where('id', $id)->first(['status', 'target', 'note']))->toBe(['status' => 302, 'target' => '/news/ferries/', 'note' => 'Moved again']);
});

it('goes back to the list it came from, its search kept, without the address that opened the form (I-856)', function (): void {
    $this->actingAs($this->editor)
        ->from('/admin/tools/redirects?search=fares&from=%2Fharbour-news%2F')
        ->post('/admin/tools/redirects', ['from' => '/harbour-news/', 'status' => 410, 'kind' => 'item', 'item' => ''])
        ->assertRedirect('/admin/tools/redirects?search=fares');
});

it('adds and changes nothing for an author or a contributor (authorization)', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);
    $id = rdSaved('/old-fares', '/news/');

    ($this->store)(['from' => '/old', 'status' => 410, 'kind' => 'item', 'item' => ''], $person)->assertForbidden();
    ($this->save)($id, ['from' => '/old-fares', 'status' => 410, 'kind' => 'item', 'item' => ''], $person)->assertForbidden();

    expect(DB::table('redirects')->pluck('status', 'source')->all())->toBe(['old-fares' => 301]);
})->with(['author', 'contributor']);

it('switches one redirect, says what it does now, and Undo switches it back', function (): void {
    $id = rdSaved('/old-fares', '/news/');
    $switch = fn (array $form) => $this->actingAs($this->editor)->from($this->list)->post('/admin/tools/redirects/switch', $form);

    $switch(['ids' => [$id], 'on' => '0'])
        ->assertRedirect($this->list)
        ->assertInertiaFlash('toast.message', 'Redirect switched off. /old-fares sends nobody on until it’s on again.')
        ->assertInertiaFlash('toast.undo', ['href' => '/admin/tools/redirects/switch', 'data' => ['ids' => [$id], 'on' => true, 'undo' => true]]);

    expect(DB::table('redirects')->where('id', $id)->value('enabled'))->toBeFalse();

    $switch(['ids' => [$id], 'on' => '1', 'undo' => '1'])
        ->assertInertiaFlash('toast.message', 'Undone.')
        ->assertInertiaFlashMissing('toast.undo');

    expect(DB::table('redirects')->where('id', $id)->value('enabled'))->toBeTrue();
});

it('switches a selection, and Undo switches back exactly the ones that changed', function (): void {
    $a = rdSaved('/a', '/news/');
    $b = rdSaved('/b', '/news/');
    $off = rdSaved('/c', '/news/', on: false);
    $switch = fn (array $form) => $this->actingAs($this->editor)->from($this->list)->post('/admin/tools/redirects/switch', $form);

    $switch(['ids' => [$a, $b, $off], 'on' => '0'])
        ->assertInertiaFlash('toast.message', '2 redirects switched off. They send nobody on until they’re on again.')
        ->assertInertiaFlash('toast.undo.data', ['ids' => [$a, $b], 'on' => true, 'undo' => true]);

    $switch(['ids' => [$a, $b], 'on' => '1', 'undo' => '1']);

    expect(DB::table('redirects')->orderBy('source')->pluck('enabled', 'source')->all())->toBe(['a' => true, 'b' => true, 'c' => false]);

    $switch(['ids' => [$a, $b], 'on' => '1'])->assertInertiaFlash('toast.message', 'Nothing changed: they were already on.');
});

it('deletes one redirect or several, after the screen asks, and says so', function (): void {
    $one = rdSaved('/old-fares', '/news/');
    $two = rdSaved('/a', '/news/');
    $three = rdSaved('/b', '/news/');
    $delete = fn (array $ids) => $this->actingAs($this->editor)->from($this->list)->post('/admin/tools/redirects/delete', ['ids' => $ids]);

    $delete([$one])->assertRedirect($this->list)->assertInertiaFlash('toast.message', 'Redirect deleted. /old-fares no longer sends readers on.');
    $delete([$two, $three])->assertInertiaFlash('toast.message', '2 redirects deleted. Their addresses no longer send readers on.');
    $delete([$two])->assertInertiaFlash('toast.message', 'Nothing deleted: they were already gone.');

    expect(DB::table('redirects')->count())->toBe(0);
});

it('marks a logged address as gone in one click, and says why when it can\'t (I-871)', function (): void {
    rdLogged(['news/ferry-fares-2025' => 312]);
    $gone = fn () => $this->actingAs($this->editor)->from('/admin/tools/redirects/404')->post('/admin/tools/redirects/gone', ['from' => '/news/ferry-fares-2025']);

    $gone()->assertRedirect('/admin/tools/redirects/404')
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Marked as gone. /news/ferry-fares-2025 now answers 410 (gone).');

    expect((array) DB::table('redirects')->first(['source', 'status', 'target_kind']))->toBe(['source' => 'news/ferry-fares-2025', 'status' => 410, 'target_kind' => null]);

    $gone()->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'A redirect from /news/ferry-fares-2025 already exists. Edit that one instead.');

    expect(DB::table('redirects')->count())->toBe(1);
});

it('switches, deletes and marks nothing for an author or a contributor (authorization)', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);
    $id = rdSaved('/old-fares', '/news/');

    $this->actingAs($person)->post('/admin/tools/redirects/switch', ['ids' => [$id], 'on' => '0'])->assertForbidden();
    $this->actingAs($person)->post('/admin/tools/redirects/delete', ['ids' => [$id]])->assertForbidden();
    $this->actingAs($person)->post('/admin/tools/redirects/gone', ['from' => '/never-was'])->assertForbidden();

    expect(DB::table('redirects')->get(['source', 'enabled'])->map(fn (object $row): array => (array) $row)->all())->toBe([['source' => 'old-fares', 'enabled' => true]]);
})->with(['author', 'contributor']);
