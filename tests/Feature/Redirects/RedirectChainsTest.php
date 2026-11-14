<?php

declare(strict_types=1);

use App\Domain\Redirects\RedirectProposal;
use App\Domain\Redirects\RedirectRefused;
use App\Domain\Redirects\RedirectRules;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // The field and the words a new redirect would be refused with, or null when it may be saved.
    $this->refusal = function (string $from, string $to = '', int $status = 301, ?string $except = null, ?string $item = null): ?array {
        try {
            app(RedirectRules::class)->check(RedirectProposal::read($from, $status, $to, $item, null, ''), $except);
        } catch (RedirectRefused $refused) {
            return [$refused->field, $refused->getMessage()];
        }

        return null;
    };
});

it('refuses a second redirect from one source, switched on or off, but not the one being edited', function (): void {
    $id = rdSaved('/old-fares', '/news/', on: false);

    expect(($this->refusal)('/Old-Fares/', '/elsewhere/'))->toBe(['source', 'A redirect from /old-fares already exists. Edit that one instead.'])
        ->and(($this->refusal)('/old-fares', '/elsewhere/', except: $id))->toBeNull();
});

it('refuses a redirect that sends readers back to itself, exact or a pattern', function (): void {
    expect(($this->refusal)('/same', '/same/'))->toBe(['to', 'That sends readers from /same back to itself.'])
        ->and(($this->refusal)('/old/[...rest]', '/old/new/[...rest]'))->toBe(['to', 'That sends readers from /old/[...rest] back to itself.']);
});

it('refuses a loop through another redirect, and a chain onward, naming the redirect readers would meet next (I-859)', function (): void {
    rdSaved('/b', '/a');
    rdSaved('/c', '/news/');
    rdSaved('/gone', status: 410);

    expect(($this->refusal)('/a', '/b'))->toBe(['to', 'That makes a loop: /a goes to /b, which goes back to /a.'])
        ->and(($this->refusal)('/d', '/c/'))->toBe(['to', '/c already redirects to /news/. Send readers there directly, so they take one step, not two.'])
        ->and(($this->refusal)('/e', '/gone'))->toBe(['to', '/gone answers 410 (gone), so readers would be sent to an address that isn’t there.']);
});

it('names an item or a file in the words of a chain onward', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $item = addrPublished($editor, 'Ferry fares');
    $file = mediaUpload($editor, mediaPdf(), 'Timetable.pdf');
    rdSaved('/fares', item: $item->id);
    rdSaved('/timetable', file: $file->id);

    expect(($this->refusal)('/a', '/fares'))->toBe(['to', '/fares already redirects to “Ferry fares”. Send readers there directly, so they take one step, not two.'])
        ->and(($this->refusal)('/b', '/timetable'))->toBe(['to', '/timetable already redirects to the file '.$file->name.'. Send readers there directly, so they take one step, not two.']);
});

it('refuses a chain into a new redirect\'s source, naming the redirect to change first, and counts one switched off (I-859)', function (): void {
    rdSaved('/a', '/b');
    rdSaved('/x', '/y/', on: false);

    expect(($this->refusal)('/b', '/c'))->toBe(['source', '/a already sends readers to /b, so they’d take two steps. Change that redirect first, or choose another address.'])
        ->and(($this->refusal)('/y', '/z', status: 302))->toBe(['source', '/x already sends readers to /y/, so they’d take two steps. Change that redirect first, or choose another address.'])
        ->and(($this->refusal)('/b2', '', status: 410))->toBeNull();
});

it('checks a pattern against every exact redirect, and two patterns both ways (I-287)', function (): void {
    rdSaved('/[slug]', '/news/[slug]');
    rdSaved('/x', '/stories/first');
    rdSaved('/blog/[...rest]', '/archive/[...rest]');

    expect(($this->refusal)('/old', '/anything'))->toBe(['to', '/[slug] already redirects to /news/[slug]. Send readers there directly, so they take one step, not two.'])
        ->and(($this->refusal)('/old', '/news/anything'))->toBeNull()
        ->and(($this->refusal)('/stories/[slug]', '/tales/[slug]'))->toBe(['source', '/x already sends readers to /stories/first, so they’d take two steps. Change that redirect first, or choose another address.'])
        ->and(($this->refusal)('/old/[slug]', '/blog/[slug]'))->toBe(['to', '/blog/[...rest] already redirects to /archive/[...rest]. Send readers there directly, so they take one step, not two.'])
        ->and(($this->refusal)('/archive/[slug]', '/kept/[slug]'))->toBe(['source', '/blog/[...rest] already sends readers to /archive/[...rest], so they’d take two steps. Change that redirect first, or choose another address.']);
});

it('never follows an item, a file or another site, whose addresses no redirect here answers', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $item = addrPublished($editor, 'Ferry fares');
    // Waiting while the item answers its own address (I-858).
    rdSaved('/ferry-fares', '/elsewhere/');

    expect(($this->refusal)('/old-fares', item: $item->id))->toBeNull()
        ->and(($this->refusal)('/podcast', 'https://podcasts.example.net/ferry-fares'))->toBeNull();
});
