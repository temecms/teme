<?php

declare(strict_types=1);

use App\Domain\Design\Operations\DiscardDesignDraft;
use App\Domain\Design\Operations\Input\DiscardDesignDraftInput;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\PublishDesign;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\DeleteNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\DeleteNavigationInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('keeps a draft out of the live site until it is published', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], (string) $this->admin->id));

    expect(app(SettingsReader::class)->string('theme.good', 'feed_layout'))->toBe('list');

    app(PublishDesign::class)->execute(new PublishDesignInput((string) $this->admin->id));

    expect(app(SettingsReader::class)->string('theme.good', 'feed_layout'))->toBe('grid')
        ->and(DB::table('settings_changes')->where('group_key', 'theme.good')->exists())->toBeTrue()
        ->and(DB::table('settings')->where('group_key', 'theme.good')->value('draft_values'))->toBeNull();
});

it('refuses an accent that fails contrast, saying by how much', function (): void {
    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['accent' => '#ffeb3b']], (string) $this->admin->id)))
        ->toThrow(ValidationException::class, ':1');
});

it('discards every draft', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], (string) $this->admin->id));

    app(DiscardDesignDraft::class)->execute(new DiscardDesignDraftInput((string) $this->admin->id));

    expect(DB::table('settings')->whereNotNull('draft_values')->count())->toBe(0);
});

it('drafts a menu for a slot only while that menu exists, and checks again when publishing', function (): void {
    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['nav_primary' => strtolower((string) Str::ulid())]], (string) $this->admin->id)))
        ->toThrow(ValidationException::class);

    $nav = navCreate($this->admin);
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['nav_primary' => $nav->id]], (string) $this->admin->id));
    app(DeleteNavigation::class)->execute(new DeleteNavigationInput($nav->id, (string) $this->admin->id));

    expect(fn () => app(PublishDesign::class)->execute(new PublishDesignInput((string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'was deleted');
    expect(app(SettingsReader::class)->string('theme.good', 'nav_primary'))->toBe('');
});

it('keeps a menu bound on Content › Navigation meanwhile, when the draft left that place alone', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['latest_header' => 'none']], (string) $this->admin->id));
    $nav = navCreate($this->admin);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->admin->id));

    expect(json_decode((string) DB::table('settings')->where('group_key', 'theme.good')->value('draft_values'), true))->toBe(['latest_header' => 'none']);

    app(PublishDesign::class)->execute(new PublishDesignInput((string) $this->admin->id));

    expect(app(SettingsReader::class)->string('theme.good', 'latest_header'))->toBe('none')
        ->and(app(SettingsReader::class)->string('theme.good', 'nav_primary'))->toBe($nav->id);
});

it('replaces a menu bound on Content › Navigation meanwhile, when the draft changed that same place', function (): void {
    $drafted = navCreate($this->admin, 'Drafted');
    $boundMeanwhile = navCreate($this->admin, 'Bound meanwhile');
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['nav_primary' => $drafted->id]], (string) $this->admin->id));
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $boundMeanwhile->id, (string) $this->admin->id));

    expect(app(SettingsReader::class)->string('theme.good', 'nav_primary'))->toBe($boundMeanwhile->id);

    app(PublishDesign::class)->execute(new PublishDesignInput((string) $this->admin->id));

    expect(app(SettingsReader::class)->string('theme.good', 'nav_primary'))->toBe($drafted->id);
});

it('stores each drafted value as the live one would be stored', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        'design' => ['accent' => '#0B5394'],
        'theme.good' => ['show_related' => '0'],
    ], (string) $this->admin->id));

    $draft = fn (string $group): array => json_decode((string) DB::table('settings')->where('group_key', $group)->value('draft_values'), true);

    expect($draft('design'))->toBe(['accent' => '#0b5394'])
        ->and($draft('theme.good'))->toBe(['show_related' => false]);
});

it('takes an undone change out of the draft, leaving nothing pending', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], (string) $this->admin->id));
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'list']], (string) $this->admin->id));

    expect(DB::table('settings')->where('group_key', 'theme.good')->exists())->toBeFalse();
});

it('keeps a row holding a draft when a write to its group changes nothing', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], (string) $this->admin->id));

    // The primary place already shows no menu, so this write changes nothing.
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', null, (string) $this->admin->id));

    expect(json_decode((string) DB::table('settings')->where('group_key', 'theme.good')->value('draft_values'), true))->toBe(['feed_layout' => 'grid']);
});

it('writes nothing when the person may not manage the design', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], (string) $this->admin->id));
    $editor = (string) User::factory()->create()->assignRole('editor')->id;

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'list']], $editor)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PublishDesign::class)->execute(new PublishDesignInput($editor)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DiscardDesignDraft::class)->execute(new DiscardDesignDraftInput($editor)))->toThrow(AuthorizationException::class);

    expect(json_decode((string) DB::table('settings')->where('group_key', 'theme.good')->value('draft_values'), true))->toBe(['feed_layout' => 'grid'])
        ->and(DB::table('settings_changes')->where('group_key', 'theme.good')->exists())->toBeFalse();
});

it('takes an accent that passes on the light background, however it reads on the dark one (I-457)', function (): void {
    // #0b5394 reaches 7.84:1 on the fixture's white, and only 2.35:1 on its dark #141413.
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['accent' => '#0b5394']], (string) $this->admin->id));

    expect(json_decode((string) DB::table('settings')->where('group_key', 'design')->value('draft_values'), true))->toBe(['accent' => '#0b5394']);
});
