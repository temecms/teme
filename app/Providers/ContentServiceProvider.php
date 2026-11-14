<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contents\BulkRunJobKind;
use App\Domain\Contents\Jobs\RecountContentMonthsJob;
use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Contents\Tree\SubtreeJobKind;
use App\Domain\Contents\Tree\SubtreeMoves;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Jobs\JobKinds;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Status\Checks\SearchLanguageRebuilding;
use App\Domain\Status\StatusChecks;
use App\Domain\Support\Security\JobActor;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Declares the Content settings group — how long trashed content is kept —
 * and builds the field registry with the built-in fields, ready for the
 * content Operations to validate against. It also rebuilds search when the
 * site language changes, and says so in the Status banner meanwhile, counts
 * the months again when the time zone changes, and tags Select all matching's job kind
 * and the background move of the pages under a page.
 * A worker's next job and a process's next request read the site's own fields again.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FieldRegistry::class, function (): FieldRegistry {
            $registry = new FieldRegistry;
            BuiltInFields::register($registry);

            return $registry;
        });

        // What this request's or job's moves of the pages under a page did, for the admin's message (I-3363).
        $this->app->scoped(SubtreeMoves::class);
    }

    public function boot(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(new SettingsGroup(
            key: 'content',
            label: 'Content',
            description: 'How content is managed across the site.',
            section: 'content',
            ability: 'settings.content.manage',
            settings: [
                Setting::number('trash_days', 'Keep trash for', 'Days an item or comment stays in the trash before it’s deleted permanently.', '0.1.0')
                    ->default(30)->min(1)->max(365),
            ],
            since: '0.1.0',
        ));

        // Another process may have changed the site's own fields (I-3296).
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(FieldRegistry::class)->refreshStored();
        });
        Event::listen(RequestHandled::class, function (): void {
            $this->app->make(FieldRegistry::class)->refreshStored();
        });

        // A new site language rebuilds search in the background, as whoever
        // changed it (I-392).
        Event::listen(SettingsChanged::class, function (SettingsChanged $change): void {
            if ($change->group === 'general' && in_array('language', $change->keys, true)) {
                ReindexSearchJob::dispatch(JobActor::of($change->actorId));
            }
        });

        // A new time zone moves items across month edges, so the months are counted again (I-3131).
        Event::listen(SettingsChanged::class, function (SettingsChanged $change): void {
            if ($change->group === 'general' && in_array('timezone', $change->keys, true)) {
                RecountContentMonthsJob::dispatch(JobActor::of($change->actorId));
            }
        });

        $this->app->make(StatusChecks::class)->register(SearchLanguageRebuilding::class);

        $this->app->tag([BulkRunJobKind::class, SubtreeJobKind::class], JobKinds::TAG);
    }
}
