<?php

/**
 * Disables an enabled plugin (I-3353, I-3445): a person who holds
 * plugins.manage, or the operator, with no password, since it takes access
 * away. Refused while an enabled plugin requires it. Keeps the row and
 * what was agreed to, and the items of the types it brought. After commit, cancels its
 * waiting and running plugin jobs as the operator. Returns its name.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Plugins\Dependencies;
use App\Domain\Plugins\Jobs\PluginJobKind;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\PublicRoutes\RootFile;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Sitemaps\Sitemaps;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Types\TypeOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Plugins\Capability;

final class DisablePlugin extends BaseOperation
{
    use AuthorizesPluginChanges;

    public function __construct(
        private readonly PluginRecords $records,
        private readonly SecurityLog $log,
        private readonly CancelJob $cancel,
        private readonly Dependencies $dependencies,
        private readonly PluginRegistry $registry,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var DisablePluginInput $input */
        $enabled = PluginRecord::query()->where('name', $input->name)->where('state', PluginRecord::ENABLED)->exists();

        if (! $enabled) {
            throw ValidationException::withMessages(['plugin' => ['It isn’t enabled.']]);
        }

        $this->refuseWhileNeeded($input->name);
    }

    protected function perform(OperationInput $input): string
    {
        /** @var DisablePluginInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->lockForUpdate()->first();

        // Checked again under the lock: Teme may have stopped it since validate().
        if ($record === null || $record->state !== PluginRecord::ENABLED) {
            throw ValidationException::withMessages(['plugin' => ['It isn’t enabled.']]);
        }

        // Under the lock EnablePlugin takes on the plugins a plugin requires, so an enable racing this disable is seen.
        $this->refuseWhileNeeded($input->name);

        $code = $record->code;

        $record->fill([
            'state' => PluginRecord::DISABLED,
            'disabled_reason' => PluginRecord::BY_USER,
            'disabled_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'disabled_at' => CarbonImmutable::now(),
        ])->save();

        $this->log->record(SecurityEventType::PluginDisabled, $input->actorId(), context: ['plugin' => $input->name]);
        // Its root files stop answering, so a stored "not found" at their addresses can stand again (docs/guides/developers/plugins/root-files.md).
        $manifest = $this->registry->find($input->name)?->manifest;
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$code, ['plugin:'.$code, ...($manifest !== null && RootFile::declaredIn($manifest) === [] ? [] : [PageTags::ROOT_FILES])]));

        // Its head tags, structured data and sitemaps leave every page (docs/guides/developers/plugins/seo.md).
        $granted = (array) ($record->consent['capabilities'] ?? []);
        $seoTags = [
            ...(in_array(Capability::PagesHead->value, $granted, true) ? [PageTags::SEO] : []),
            ...(in_array(Capability::Sitemaps->value, $granted, true) ? [Sitemaps::CACHE_TAG] : []),
        ];

        if ($seoTags !== []) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$code, $seoTags));
        }

        // Its insertions and link attributes leave the pages that drew them, after commit (I-064).
        if (array_intersect([Capability::ContentInsert->value, Capability::PagesInsert->value, Capability::ContentLinks->value], $record->consent['capabilities'] ?? []) !== []) {
            app(InsertionsChanged::class)->notify();
        }

        // Its content types leave the menu, the editor and the site; their items stay (docs/architecture/plugins.md, *Content types from a plugin*).
        $ownsTypes = TypeOwners::ownedBy($input->name) !== ['types' => [], 'taxonomies' => [], 'fields' => []];

        if ($ownsTypes) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));
        }

        $code = $record->code;

        DB::afterCommit(function () use ($code, $ownsTypes): void {
            if ($ownsTypes) {
                TypeOwners::changed();
            }

            $this->records->changed();
            $this->cancelPluginJobs($code);
        });

        return $input->name;
    }

    /**
     * Refuses, naming them, while an enabled plugin requires this one
     * (docs/plans/plugin-sockets-3.md). Safe mode switches everything off
     * without asking this.
     *
     * @throws ValidationException
     */
    private function refuseWhileNeeded(string $name): void
    {
        $dependents = $this->dependencies->enabledDependents($name);

        if ($dependents !== []) {
            $names = Arr::join(array_map(fn (string $dependent): string => $this->registry->find($dependent)?->manifest?->label() ?: $dependent, $dependents), ', ', ' '.__('and').' ');

            throw ValidationException::withMessages(['plugin' => [__(':dependents needs it. Disable :dependents first.', ['dependents' => $names])]]);
        }
    }

    /**
     * Ends every waiting or running plugin-job owned by this plugin's code,
     * as the operator (I-3445).
     */
    private function cancelPluginJobs(string $code): void
    {
        $ids = TrackedJob::query()
            ->where('kind', PluginJobKind::KEY)
            ->where('owner', $code)
            ->whereIn('state', [JobState::Waiting->value, JobState::Running->value])
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        $previous = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            foreach ($ids as $id) {
                try {
                    $this->cancel->execute(new CancelJobInput((string) $id, null));
                } catch (ValidationException) {
                    // Another cancel or finish won the race; disable still finishes.
                }
            }
        } finally {
            app()->instance(Elevation::class, $previous);
        }
    }
}
