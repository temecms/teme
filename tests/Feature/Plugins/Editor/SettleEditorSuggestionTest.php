<?php

declare(strict_types=1);

use App\Domain\Plugins\Editor\PanelTooSlow;
use App\Domain\Plugins\Operations\Input\SettleEditorSuggestionInput;
use App\Domain\Plugins\Operations\SettleEditorSuggestion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Editor\EditorPanelContext;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/Linkfinder.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    ['faults' => $this->faults, 'clock' => $this->clock] = linkfinderEnabled();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->post = lifecycleDraft($this->editor, 'Pruning roses');

    // The plugin's own record of what was used, written from settled().
    Schema::create('linkfinder_seen', function (Blueprint $table): void {
        $table->id();
        $table->string('suggestion');
        $table->boolean('used');
    });

    Linkfinder::$settled = function (string $suggestion, bool $used, EditorPanelContext $context): void {
        DB::table('linkfinder_seen')->insert(['suggestion' => $suggestion, 'used' => $used]);
    };

    $this->settle = fn (bool $used = true, ?User $person = null, string $suggestion = 'link-1'): bool => app(SettleEditorSuggestion::class)->execute(new SettleEditorSuggestionInput(
        code: 'linkfinder',
        panel: 'links',
        suggestion: $suggestion,
        contentId: $this->post->id,
        used: $used,
        actorId: (string) ($person ?? $this->editor)->id,
    ));
});

afterEach(function (): void {
    Linkfinder::reset();
    PluginFixtures::forget();
});

it('tells the plugin a suggestion was used or discarded, and keeps what it writes', function (): void {
    ($this->settle)(true);
    ($this->settle)(false, suggestion: 'title');

    expect(Linkfinder::$settles)->toBe([['link-1', true], ['title', false]])
        ->and(DB::table('linkfinder_seen')->orderBy('id')->get(['suggestion', 'used'])->map(fn (object $row): array => [$row->suggestion, $row->used])->all())
        ->toBe([['link-1', true], ['title', false]]);
});

it('rolls back the plugin’s own write when it refuses, saying its sentence', function (): void {
    Linkfinder::$settled = function (string $suggestion): void {
        DB::table('linkfinder_seen')->insert(['suggestion' => $suggestion, 'used' => true]);

        throw new WriteRefused('That link was already used elsewhere.');
    };

    expect(fn () => ($this->settle)())->toThrow(ValidationException::class, 'That link was already used elsewhere.')
        ->and(DB::table('linkfinder_seen')->count())->toBe(0)
        ->and($this->faults->seen)->toBe([]);
});

it('rolls back the plugin’s own write, and records why, when it throws or runs over its 2 seconds', function (Closure $arrange, string $failure): void {
    $arrange($this);

    expect(fn () => ($this->settle)())->toThrow(ValidationException::class, 'Link Finder couldn’t save this. Nothing was changed.')
        ->and(DB::table('linkfinder_seen')->count())->toBe(0)
        ->and($this->faults->seen)->toHaveCount(1)
        ->and($this->faults->seen[0][1])->toBe('tests/linkfinder#editor-panel')
        ->and($this->faults->seen[0][3])->toBeInstanceOf($failure);
})->with([
    'a throw' => [function ($test): void {
        Linkfinder::$settled = function (string $suggestion): void {
            DB::table('linkfinder_seen')->insert(['suggestion' => $suggestion, 'used' => true]);

            throw new RuntimeException('The index is gone.');
        };
    }, RuntimeException::class],
    'over its budget' => [fn ($test) => $test->clock->steps = [0, 2_001_000_000], PanelTooSlow::class],
]);

it('refuses a person who doesn’t see the panel, and a malformed id, writing nothing and never asking the plugin', function (Closure $call, string $exception): void {
    expect(fn () => $call($this))->toThrow($exception)
        ->and(DB::table('linkfinder_seen')->count())->toBe(0)
        ->and(Linkfinder::$settles)->toBe([]);
})->with([
    'an author without the permission' => [fn ($test) => ($test->settle)(person: User::factory()->create()->assignRole('author')), AuthorizationException::class],
    'a malformed id' => [fn ($test) => ($test->settle)(suggestion: 'Not An Id'), ValidationException::class],
]);

it('answers over HTTP with the panel as it now stands', function (): void {
    $this->actingAs($this->editor)
        ->postJson(route('content.panels.suggestions', ['content' => $this->post->id, 'code' => 'linkfinder', 'panel' => 'links', 'suggestion' => 'link-1']), ['used' => false])
        ->assertOk()
        ->assertJsonPath('status', 'No links suggested yet.');

    expect(Linkfinder::$settles)->toBe([['link-1', false]]);
});
