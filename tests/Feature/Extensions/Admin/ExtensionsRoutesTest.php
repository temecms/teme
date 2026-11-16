<?php

declare(strict_types=1);

use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.extensions.path' => null, 'teme.extensions.packagist' => true]);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    app()->forgetInstance(Elevation::class);
    $this->confirmed = scConfirmed();
    $this->zip = function (): string {
        $file = tempnam(sys_get_temp_dir(), 'teme-ext-zip-');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', '{"name": "acme/hello", "version": "1.0.0", "type": "teme-plugin"}');
        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    };
});

it('asks for the password before recording a request', function (): void {
    $this->actingAs($this->admin)->from('/admin/plugins')
        ->post('/admin/extensions/requests', ['action' => 'install', 'kind' => 'plugin', 'package' => 'acme/hello'])
        ->assertRedirect(route('password.confirm'));

    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('asks for the password once a window: a second request inside it goes through without asking, and one after it asks again', function (): void {
    $session = $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins');

    $session->post('/admin/extensions/requests', ['action' => 'install', 'kind' => 'plugin', 'package' => 'acme/hello'])->assertRedirect('/admin/plugins');
    DB::table('extension_requests')->update(['status' => 'done', 'finished_at' => now()]);

    $this->travel(5)->minutes();
    $session->post('/admin/extensions/requests', ['action' => 'install', 'kind' => 'plugin', 'package' => 'acme/other'])->assertRedirect('/admin/plugins');
    DB::table('extension_requests')->update(['status' => 'done', 'finished_at' => now()]);

    expect(DB::table('extension_requests')->count())->toBe(2);

    $this->travel(20)->minutes();
    $session->post('/admin/extensions/requests', ['action' => 'install', 'kind' => 'plugin', 'package' => 'acme/third'])->assertRedirect(route('password.confirm'));

    expect(DB::table('extension_requests')->count())->toBe(2);
});

it('records a request, says so, and flashes its ID for the sheet', function (): void {
    $response = $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->post('/admin/extensions/requests', ['action' => 'install', 'kind' => 'plugin', 'package' => 'acme/hello', 'constraint' => '^1.0'])
        ->assertRedirect('/admin/plugins')
        ->assertInertiaFlash('toast.message', 'Request recorded. The installer carries it out; nothing is enabled until you enable it.');

    $row = DB::table('extension_requests')->first();
    $response->assertInertiaFlash('extensionRequest', $row->id);
    expect($row->package)->toBe('acme/hello')->and((int) $row->requested_by)->toBe($this->admin->id);
});

it('shows a refusal as a toast and records nothing', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->post('/admin/extensions/requests', ['action' => 'install', 'kind' => 'plugin', 'package' => 'laravel/framework'])
        ->assertRedirect('/admin/plugins')
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'laravel/framework is part of Teme’s build, so it can’t be installed again.');

    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('refuses a rebuild asked for over HTTP before it reaches the Operation', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->post('/admin/extensions/requests', ['action' => 'rebuild', 'kind' => 'plugin'])
        ->assertSessionHasErrors('action');

    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('cancels and agrees to a request', function (): void {
    $id = extRequestRow('waiting', ['needs' => json_encode(['composerPlugins' => ['acme/plugin']])]);

    $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->post("/admin/extensions/requests/{$id}/agree", ['needs' => ['composerPlugins' => ['acme/plugin']]])
        ->assertInertiaFlash('toast.message', 'Agreed. The installer goes on.');
    $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->post("/admin/extensions/requests/{$id}/cancel")
        ->assertInertiaFlash('toast.message', 'Request cancelled.');

    expect(DB::table('extension_requests')->where('id', $id)->first())
        ->agreed_by->not->toBeNull()
        ->status->toBe('cancelled');
});

it('answers the request sheet’s poll with one request', function (): void {
    $id = extRequestRow('running', ['step' => 'fetching']);

    $this->actingAs($this->admin)->getJson("/admin/extensions/requests/{$id}")
        ->assertOk()
        ->assertJsonPath('request.id', $id)
        ->assertJsonPath('request.step', 'fetching')
        ->assertJsonPath('installerPresent', false);

    $this->actingAs($this->admin)->getJson('/admin/extensions/requests/'.strtolower((string) Str::ulid()))->assertNotFound();
});

it('searches Packagist and lists a package’s versions', function (): void {
    Http::fake([
        'packagist.org/search.json*' => Http::response(['results' => [['name' => 'acme/hello', 'description' => 'Hi', 'downloads' => 3]]]),
        'repo.packagist.org/p2/acme/hello.json' => Http::response(['packages' => ['acme/hello' => [['version' => '1.0.0']]]]),
    ]);

    $this->actingAs($this->admin)->getJson('/admin/extensions/search?q=hello&kind=plugin')
        ->assertOk()->assertJsonPath('results.0.name', 'acme/hello');
    $this->actingAs($this->admin)->getJson('/admin/extensions/search?package=acme/hello')
        ->assertOk()->assertJsonPath('versions', ['1.0.0']);
});

it('saves a source with its key and never sends the key back, then removes it', function (): void {

    $responses = [
        $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
            ->post('/admin/extensions/sources', ['kind' => 'git', 'label' => 'Acme', 'url' => 'https://git.example.com/acme.git', 'credential' => 'ghp_never-shown-9876'])
            ->assertInertiaFlash('toast.message', 'Saved. Teme never shows a key again.'),
    ];
    $id = (string) DB::table('extension_sources')->value('id');
    $responses[] = $this->actingAs($this->admin)->getJson('/admin/extensions/search?q=x');

    foreach ($responses as $response) {
        expect((string) $response->getContent().json_encode(session()->all()))->not->toContain('ghp_never-shown-9876');
    }

    $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->delete("/admin/extensions/sources/{$id}")
        ->assertInertiaFlash('toast.message', 'Source removed, with its key.');

    expect(DB::table('extension_sources')->count())->toBe(0);
});

it('never flashes a source’s key into the session when the form is refused', function (): void {

    $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->post('/admin/extensions/sources', ['kind' => 'git', 'label' => str_repeat('a', 101), 'url' => 'https://git.example.com/acme.git', 'credential' => 'ghp_x'])
        ->assertSessionHasErrors('label');

    expect(json_encode(session()->all()))->not->toContain('ghp_x')
        ->and(DB::table('extension_sources')->count())->toBe(0);
});

it('keeps an upload and flashes its package and version', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)->from('/admin/plugins')
        ->post('/admin/extensions/uploads', ['archive' => UploadedFile::fake()->createWithContent('hello.zip', ($this->zip)())])
        ->assertInertiaFlash('toast.message', 'acme/hello 1.0.0 uploaded. Ask to install it.')
        ->assertInertiaFlash('extensionUpload.package', 'acme/hello');

    expect(DB::table('extension_uploads')->value('file_name'))->toBe('hello.zip');
});

it('refuses everyone without extensions.install on every route, recording nothing', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);
    $id = extRequestRow('waiting', ['needs' => json_encode(['x' => [1]])]);
    $session = $this->actingAs($person)->withSession($this->confirmed);

    $session->post('/admin/extensions/requests', ['action' => 'install', 'kind' => 'plugin', 'package' => 'acme/other'])->assertForbidden();
    $session->post("/admin/extensions/requests/{$id}/cancel")->assertForbidden();
    $session->post("/admin/extensions/requests/{$id}/agree", ['needs' => ['x' => [1]]])->assertForbidden();
    $session->get("/admin/extensions/requests/{$id}")->assertForbidden();
    $session->get('/admin/extensions/search?q=hello')->assertForbidden();
    $session->post('/admin/extensions/sources', ['kind' => 'git', 'label' => 'A', 'url' => 'https://a.example.com'])->assertForbidden();
    $session->delete('/admin/extensions/sources/'.strtolower((string) Str::ulid()))->assertForbidden();
    $session->post('/admin/extensions/uploads', ['archive' => UploadedFile::fake()->createWithContent('hello.zip', ($this->zip)())])->assertForbidden();

    expect(DB::table('extension_requests')->count())->toBe(1)
        ->and(DB::table('extension_requests')->value('status'))->toBe('waiting')
        ->and(DB::table('extension_sources')->count())->toBe(0)
        ->and(DB::table('extension_uploads')->count())->toBe(0);
})->with(['editor', 'author', 'contributor']);
