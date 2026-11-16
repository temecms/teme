<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\Operations\RemovePluginData;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\Operations\ApprovePluginScript;
use App\Domain\Site\Insertions\Operations\Input\ApprovePluginScriptInput;
use App\Domain\Site\Insertions\Operations\Input\RefusePluginScriptInput;
use App\Domain\Site\Insertions\Operations\Input\RequestPluginScriptInput;
use App\Domain\Site\Insertions\Operations\Input\RevokePluginScriptInput;
use App\Domain\Site\Insertions\Operations\RefusePluginScript;
use App\Domain\Site\Insertions\Operations\RequestPluginScript;
use App\Domain\Site\Insertions\Operations\RevokePluginScript;
use App\Domain\Site\Insertions\Scripts\ApprovedScripts;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\Site\Policy\PolicyMode;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('billboard');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();

    // Deciding asks for the password again; each test elevates where it means to.
    app()->forgetInstance(Elevation::class);

    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
});

/** Asks, for this person, that the script be approved for the billboard. */
function psRequest(User $person, string $script = Billboard::SCRIPT, string $name = 'Ad slots'): string
{
    return app(RequestPluginScript::class)->execute(new RequestPluginScriptInput('billboard', $name, $script, (string) $person->id));
}

/** Decides on the billboard's script with this text, as this person, with the password asked again. */
function psDecide(string $decision, User $person, string $script = Billboard::SCRIPT): mixed
{
    $id = (string) PluginScript::query()->where('script', $script)->value('id');

    return scElevated(fn (): mixed => match ($decision) {
        'approve' => app(ApprovePluginScript::class)->execute(new ApprovePluginScriptInput($id, (string) $person->id)),
        'refuse' => app(RefusePluginScript::class)->execute(new RefusePluginScriptInput($id, (string) $person->id)),
        default => app(RevokePluginScript::class)->execute(new RevokePluginScriptInput($id, (string) $person->id)),
    });
}

/**
 * The security log's plugin script events, oldest first: each one's type and context.
 *
 * @return list<array{string, array<string, mixed>}>
 */
function psLog(): array
{
    return DB::table('security_events')->where('type', 'like', 'plugin_script.%')->orderBy('id')->get()
        ->map(fn (object $row): array => [(string) $row->type, (array) json_decode((string) $row->context, true)])
        ->values()
        ->all();
}

it('keeps a plugin\'s inline script only once a person approved its exact text, and lists its hash in the page\'s policy', function (): void {
    $hash = PagePolicy::hash(Billboard::SCRIPT);

    expect(psRequest($this->author))->toBe(PluginScript::WAITING)
        ->and(psRequest($this->author))->toBe(PluginScript::WAITING)
        ->and(PluginScript::query()->count())->toBe(1)
        ->and((string) addrGet('/')->getContent())->not->toContain(Billboard::SCRIPT);

    psDecide('approve', $this->admin);
    $response = addrGet('/')->assertOk();

    expect((string) $response->getContent())->toContain('<script>'.Billboard::SCRIPT.'</script>')
        ->not->toContain('alert(1)')
        ->and((string) $response->headers->get((string) PolicyMode::current()->header()))->toContain($hash)
        ->and(psRequest($this->author))->toBe(PluginScript::APPROVED);
});

it('reads a script asked for with Windows line endings as the same text', function (): void {
    $lines = "var a = 1;\r\nvar b = 2;";
    psRequest($this->author, $lines);

    expect(psRequest($this->author, "var a = 1;\nvar b = 2;"))->toBe(PluginScript::WAITING)
        ->and(PluginScript::query()->sole()->script)->toBe("var a = 1;\nvar b = 2;");
});

it('removes a refused or revoked script from pages again, and lets the plugin ask once more after a revoke', function (): void {
    psRequest($this->author);
    psDecide('refuse', $this->admin);

    expect(psRequest($this->author))->toBe(PluginScript::REFUSED)
        ->and((string) addrGet('/')->getContent())->not->toContain(Billboard::SCRIPT);

    psDecide('revoke', $this->admin);
    expect(PluginScript::query()->count())->toBe(0)
        ->and(psRequest($this->author))->toBe(PluginScript::WAITING);

    psDecide('approve', $this->admin);
    expect((string) addrGet('/')->getContent())->toContain(Billboard::SCRIPT);

    psDecide('revoke', $this->admin);
    expect((string) addrGet('/')->getContent())->not->toContain(Billboard::SCRIPT)
        ->and(array_column(psLog(), 0))->toBe(['plugin_script.refused', 'plugin_script.revoked', 'plugin_script.approved', 'plugin_script.revoked']);
});

it('logs each decision with the script before and after it', function (): void {
    psRequest($this->author);
    $id = (string) PluginScript::query()->value('id');

    psDecide('approve', $this->admin);
    psDecide('revoke', $this->admin);
    [[$approved, $approval], [, $revoke]] = psLog();

    expect($approved)->toBe('plugin_script.approved')
        ->and($approval)->toMatchArray(['script' => $id, 'plugin' => 'billboard', 'name' => 'Ad slots', 'hash' => ApprovedScripts::hash(Billboard::SCRIPT), 'before_state' => 'waiting', 'after_state' => 'approved', 'before_script' => Billboard::SCRIPT, 'after_script' => Billboard::SCRIPT])
        ->and($revoke)->toMatchArray(['before_state' => 'approved', 'after_state' => null, 'after_script' => null]);
});

it('holds at most twenty scripts waiting for one plugin', function (): void {
    foreach (range(1, PluginScript::MOST_WAITING) as $n) {
        psRequest($this->author, "slot({$n});");
    }

    expect(fn () => psRequest($this->author, 'slot(21);'))
        ->toThrow(ValidationException::class, 'Too many scripts wait for approval. Ask an administrator to look at Settings › Site code.')
        ->and(PluginScript::query()->count())->toBe(PluginScript::MOST_WAITING);

    psDecide('approve', $this->admin, 'slot(1);');

    expect(psRequest($this->author, 'slot(21);'))->toBe(PluginScript::WAITING);
});

it('writes nothing when asking is refused: for nobody, for a plugin that inserts nothing, or for a script it couldn\'t keep', function (Closure $ask, string $message): void {
    expect(fn () => $ask($this->author))->toThrow(Exception::class, $message)
        ->and(PluginScript::query()->count())->toBe(0);
})->with([
    'nobody' => [fn (User $author) => app(RequestPluginScript::class)->execute(new RequestPluginScriptInput('billboard', 'Ad slots', 'x();', '999999')), 'A plugin asks for a script on behalf of a person.'],
    'the operator' => [fn (User $author) => app(RequestPluginScript::class)->execute(new RequestPluginScriptInput('billboard', 'Ad slots', 'x();', 'operator')), 'A plugin asks for a script on behalf of a person.'],
    'a plugin not enabled' => [fn (User $author) => app(RequestPluginScript::class)->execute(new RequestPluginScriptInput('elsewhere', 'Ad slots', 'x();', (string) $author->id)), 'Only an enabled plugin that inserts content may ask for a script.'],
    'no name' => [fn (User $author) => psRequest($author, 'x();', ' '), 'A script’s name is 1 to 80 characters.'],
    'no script' => [fn (User $author) => psRequest($author, "  \n"), 'The script is empty.'],
    'too long a script' => [fn (User $author) => psRequest($author, str_repeat('x', PluginScript::LONGEST + 1)), 'A script is at most 4,096 characters.'],
]);

it('writes nothing when deciding is refused: for an editor, without the password, or while the host disallows site code', function (string $decision): void {
    psRequest($this->author);
    if ($decision !== 'approve') {
        psDecide('approve', $this->admin);
    }
    $before = DB::table('plugin_scripts')->get()->all();
    $logged = count(psLog());
    $id = (string) PluginScript::query()->value('id');
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => psDecide($decision, $editor))->toThrow(AuthorizationException::class, 'Not permitted to change site code.')
        ->and(fn () => app(ApprovePluginScript::class)->execute(new ApprovePluginScriptInput($id, (string) $this->admin->id)))
        ->toThrow(ElevationRequiredException::class);

    config(['teme.disallow_site_code' => true]);

    expect(fn () => psDecide($decision, $this->admin))->toThrow(AuthorizationException::class, 'The host has switched site code off, so it can’t be changed here.')
        ->and(DB::table('plugin_scripts')->get()->all())->toEqual($before)
        ->and(count(psLog()))->toBe($logged);
})->with(['approve', 'refuse', 'revoke']);

it('keeps no inline script on pages while the host disallows site code, approved or not', function (): void {
    psRequest($this->author);
    psDecide('approve', $this->admin);
    config(['teme.disallow_site_code' => true]);

    expect((string) addrGet('/')->getContent())->not->toContain(Billboard::SCRIPT);
});

it('lets a plugin ask through its door for the signed-in person, and refuses when no one is using it', function (): void {
    expect(fn () => Billboard::$scripts?->request('Ad slots', Billboard::SCRIPT))->toThrow(WriteRefused::class)
        ->and(PluginScript::query()->count())->toBe(0);

    $this->actingAs($this->author);
    Billboard::$scripts?->request('Ad slots', Billboard::SCRIPT);

    expect(PluginScript::query()->sole()->only(['plugin_code', 'name', 'state', 'requested_by']))
        ->toBe(['plugin_code' => 'billboard', 'name' => 'Ad slots', 'state' => 'waiting', 'requested_by' => $this->author->id]);
});

it('forgets a plugin\'s scripts when its data is removed, and only that plugin\'s', function (): void {
    psRequest($this->author);
    PluginFixtures::use('tables');
    $manifest = app(PluginRegistry::class)->all()['tests/tables']->manifest;
    scElevated(fn (): string => app(EnablePlugin::class)->execute(new EnablePluginInput('tests/tables', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null)));
    DB::table('plugin_scripts')->insert(['id' => (string) Str::ulid(), 'plugin_code' => 'tables', 'name' => 'Old', 'hash' => ApprovedScripts::hash('old();'), 'script' => 'old();', 'state' => 'approved', 'requested_at' => now()]);
    scElevated(fn (): mixed => app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null)));

    scElevated(fn (): array => app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null)));

    expect(PluginScript::query()->pluck('plugin_code')->all())->toBe(['billboard']);
});

it('forgets a plugin\'s approvals with its data, so enabled again its old script is removed', function (): void {
    $copy = PluginFixtures::copy('billboard', ['owns' => ['tables' => ['billboard_slots']]], files: [
        'database/migrations/2027_01_01_000000_create_billboard_slots.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billboard_slots', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
    ]);
    $enable = function (): void {
        $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
        scElevated(fn (): string => app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null)));
    };
    $disable = fn (): mixed => scElevated(fn (): mixed => app(DisablePlugin::class)->execute(new DisablePluginInput('tests/billboard', null)));

    $disable();
    PluginFixtures::use($copy);
    $enable();
    psRequest($this->author);
    psDecide('approve', $this->admin);
    expect((string) addrGet('/')->getContent())->toContain(Billboard::SCRIPT);

    $disable();
    scElevated(fn (): array => app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/billboard', null)));
    $enable();

    expect(PluginScript::query()->count())->toBe(0)
        ->and((string) addrGet('/')->getContent())->toContain('data-test="billboard-bottom"')
        ->not->toContain(Billboard::SCRIPT);
});
