<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
});

it('starts a saved type’s new items as its own form says, on a site whose Discussion settings were saved before it', function (): void {
    cmSettings(['open_for' => ['post']]);
    ctCreate($this->admin, ['comments' => 'open']);

    expect(lifecycleDraft($this->admin, 'Arrival', 'film')->comment_status)->toBe(Discussion::Open);

    app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition(['comments' => 'closed']), (string) $this->admin->id));

    expect(lifecycleDraft($this->admin, 'Heat', 'film')->comment_status)->toBe(Discussion::Closed)
        ->and(DiscussionSettings::current()->forNewItem('post'))->toBe(Discussion::Open)
        ->and(DiscussionSettings::current()->forNewItem('page'))->toBe(Discussion::Closed);
});

it('lists only code’s types in Settings › Discussion’s New items are open for comments', function (): void {
    ctCreate($this->admin, ['comments' => 'open']);
    $openFor = app(SettingsRegistry::class)->find('discussion')?->setting('open_for');

    expect($openFor?->options)->toBe(['post' => 'Posts', 'page' => 'Pages'])
        ->and($openFor?->default)->toBe(['post']);
});
