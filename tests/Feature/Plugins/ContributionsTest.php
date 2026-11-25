<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Cards\CardInstance;
use Teme\Contracts\Cards\HasCards;
use Teme\Contracts\Cards\PluginCard;
use Teme\Contracts\Status\StatusNotice;
use Tests\Fixtures\Plugins\Lists\NoticingPlugin;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Only the plugins' checks: core's own may have something to say on a test site.
    app()->instance(StatusChecks::class, new StatusChecks);
});

/**
 * What Example sync's teme-plugin.json adds to the admin.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function exampleContributes(): array
{
    return [
        'settings' => [
            ['key' => 'token', 'type' => 'secret', 'label' => 'Token', 'description' => 'Lets Teme tell your workspace.'],
            ['key' => 'project', 'type' => 'text', 'label' => 'Project', 'description' => 'The workspace project to tell.'],
        ],
        'admin' => [
            ['surface' => 'tools', 'id' => 'open', 'label' => 'Open Sync', 'description' => 'Your keyword workspace.', 'icon' => 'external-link', 'to' => 'https://sync.example/projects'],
            ['surface' => 'tools', 'id' => 'settings', 'label' => 'Sync settings', 'to' => 'settings'],
        ],
    ];
}

it('registers a plugin’s settings, links and checks together, under its name', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $plugin = new NoticingPlugin(new StatusNotice('warning', 'Example sync needs its token.', true));

    $problems = app(Contributions::class)->add('acme/example-sync', 'example-sync', 'Example sync', '1.2.0', exampleContributes(), $plugin);

    $settings = app(SettingsRegistry::class);
    $links = app(ToolLinks::class)->all();

    expect($problems)->toBe([])
        ->and($settings->find('plugin.example-sync')?->settings)->toHaveCount(2)
        ->and($settings->find('plugin.example-sync')?->sensitive)->toBeTrue()
        ->and($settings->ownerOf('plugin.example-sync'))->toBe('acme/example-sync')
        ->and(array_map(fn (ToolLink $link): array => [$link->owner, $link->code, $link->id, $link->icon, $link->to], $links))->toBe([
            ['acme/example-sync', 'example-sync', 'open', 'external-link', 'https://sync.example/projects'],
            ['acme/example-sync', 'example-sync', 'settings', 'plug', 'settings'],
        ])
        ->and($links[1]->description)->toBe('Added by the Example sync plugin.')
        ->and(app(StatusChecks::class)->forUser($admin)?->actionLabel)->toBe('Open Example sync’s settings');
});

it('registers nothing when any part is wrong, and says why', function (): void {
    $contributes = exampleContributes();
    $contributes['admin'][0]['to'] = 'http://sync.example';

    $problems = app(Contributions::class)->add('acme/example-sync', 'example-sync', 'Example sync', '1.2.0', $contributes, new NoticingPlugin(null));

    expect($problems)->toBe(['A link must go to settings or to an https:// address.'])
        ->and(app(SettingsRegistry::class)->find('plugin.example-sync'))->toBeNull()
        ->and(app(ToolLinks::class)->all())->toBe([]);
});

it('takes a plugin’s checks only when it implements ChecksStatus', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $notice = new StatusNotice('error', 'Look at me.');
    // Has the method, but never said it raises notices.
    $lookalike = new readonly class($notice)
    {
        public function __construct(private StatusNotice $notice) {}

        /** @return list<object> */
        public function statusChecks(): array
        {
            return (new NoticingPlugin($this->notice))->statusChecks();
        }
    };

    expect(app(Contributions::class)->add('acme/lookalike', 'lookalike', 'Lookalike', '1.0.0', [], $lookalike))->toBe([])
        ->and(app(StatusChecks::class)->forUser($admin))->toBeNull();

    app(Contributions::class)->add('acme/noticing', 'noticing', 'Noticing', '1.0.0', [], new NoticingPlugin($notice));

    expect(app(StatusChecks::class)->forUser($admin)?->message)->toBe('Look at me.');
});

/**
 * One card, as cardbox declares it, with its fields changed by $change.
 *
 * @param  array<string, mixed>  $change
 *
 * @return array<string, mixed>
 */
function cardContributes(array $change = []): array
{
    return ['cards' => [[
        'id' => 'note',
        'label' => 'Note',
        'description' => 'A short note.',
        'fields' => [['key' => 'body', 'type' => 'text', 'label' => 'Text']],
        ...$change,
    ]]];
}

/**
 * A card that draws nothing.
 */
function quietCard(): PluginCard
{
    return new class implements PluginCard
    {
        public function render(CardInstance $card): string
        {
            return '';
        }
    };
}

it('refuses cards it can’t use, and says why', function (array $contributes, string $why): void {
    expect(app(ContributionsValidator::class)->errors($contributes, '1.0.0', ['pages.insert']))->toContain($why);
})->with([
    'two with one id' => [['cards' => [...cardContributes()['cards'], ...cardContributes()['cards']]], 'Two cards can’t share the id note.'],
    'a secret field' => [cardContributes(['fields' => [['key' => 'token', 'type' => 'secret', 'label' => 'Token']]]), 'A card’s field can’t be of the type secret.'],
    'a code field' => [cardContributes(['fields' => [['key' => 'html', 'type' => 'code', 'label' => 'Code']]]), 'A card’s field can’t be of the type code.'],
    'a bad id' => [cardContributes(['id' => 'Note']), 'A card’s id must be 2 to 41 lower-case letters, digits and hyphens, starting with a letter.'],
    'no description' => [cardContributes(['description' => '']), 'A card needs a description of 1 to 160 characters.'],
]);

it('reads cards only with pages.insert', function (): void {
    expect(app(ContributionsValidator::class)->errors(cardContributes(), '1.0.0', ['content.insert']))->toContain('A plugin’s cards need pages.insert.')
        ->and(app(ContributionsValidator::class)->errors(cardContributes(), '1.0.0', ['pages.insert']))->toBe([]);
});

it('starts a plugin only when its declared cards and its card classes match', function (object $plugin, string $why): void {
    expect(app(Contributions::class)->add('tests/cards', 'cards', 'Cards', '1.0.0', cardContributes(), $plugin))->toBe([$why]);
})->with([
    'no class for a declared card' => [fn (): object => new class implements HasCards
    {
        public function cards(): array
        {
            return [];
        }
    }, 'It declares card "note" but has no '.PluginCard::class.' for it.'],
    'a class for a card it doesn’t declare' => [fn (): object => new class implements HasCards
    {
        public function cards(): array
        {
            return ['note' => quietCard(), 'extra' => quietCard()];
        }
    }, 'It has a class for card "extra", which isn’t declared.'],
    'no HasCards' => [fn (): object => new stdClass, 'It declares cards but doesn’t implement '.HasCards::class.'.'],
]);
