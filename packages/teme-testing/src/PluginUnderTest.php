<?php

declare(strict_types=1);

namespace Teme\Testing;

use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Jobs\DueSchedules;
use App\Domain\Plugins\Jobs\PluginJobKind;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\Queries\SettingsForm;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use LogicException;
use PHPUnit\Framework\Assert;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Plugins\Plugin;
use Throwable;

/**
 * One plugin on the test's site: enabled, disabled, configured and its jobs
 * run through the same Operations and doors the admin and a running site
 * use, so a test meets every check a person would.
 */
final class PluginUnderTest
{
    /** Who enabled it in this test, whom its jobs run as. */
    private ?Person $enabledBy = null;

    /**
     * @param  Closure(): Person  $administrator  a new administrator, for a step no one is named for
     */
    public function __construct(
        public readonly string $name,
        public readonly string $code,
        private readonly Acting $acting,
        private readonly Closure $administrator,
    ) {}

    /**
     * Enables the plugin through EnablePlugin as this person, a new
     * administrator when none is named, agreeing to exactly what its manifest
     * asks for, then starts it as the next request would. Fails the test with
     * the refusal, or with why it didn't start.
     */
    public function enable(?Person $as = null): self
    {
        $as ??= ($this->administrator)();
        $manifest = app(PluginRegistry::class)->find($this->name)?->manifest;

        if ($manifest === null) {
            Assert::fail("{$this->name} has no teme-plugin.json Teme can read.");
        }

        $this->acting->as($as, fn (): mixed => app(EnablePlugin::class)->execute(new EnablePluginInput(
            $this->name,
            $manifest->version(),
            $manifest->capabilities(),
            $manifest->hosts(),
            $manifest->hooks(),
            (string) $as->id,
        )), 'Enabling '.$this->name);

        $this->enabledBy = $as;
        $this->start();

        return $this;
    }

    /**
     * Disables the plugin through DisablePlugin as this person, a new
     * administrator when none is named; it hears no hook after.
     */
    public function disable(?Person $as = null): self
    {
        $as ??= ($this->administrator)();

        $this->acting->as($as, fn (): mixed => app(DisablePlugin::class)->execute(new DisablePluginInput($this->name, (string) $as->id)), 'Disabling '.$this->name);
        app(HookSubscriptions::class)->forget($this->name);

        return $this;
    }

    /**
     * Changes settings through UpdateSettings, with the group's current
     * version: the plugin's own group (`plugin.<code>`) when given only
     * values, or the group named. A secret setting goes in the secrets the
     * Operation keeps apart. Acts as this person, or as whoever enabled the
     * plugin, or a new administrator. Fails the test with the refusal.
     *
     * @param  array<string, mixed>|string  $valuesOrGroup
     * @param  array<string, mixed>|Person|null  $valuesOrAs
     */
    public function changeSettings(array|string $valuesOrGroup, array|Person|null $valuesOrAs = null, ?Person $as = null): self
    {
        if (is_string($valuesOrGroup)) {
            $group = $valuesOrGroup;
            $values = is_array($valuesOrAs) ? $valuesOrAs : [];
        } else {
            $group = 'plugin.'.$this->code;
            $values = $valuesOrGroup;
            $as = $valuesOrAs instanceof Person ? $valuesOrAs : $as;
        }

        $as ??= $this->enabledBy ?? ($this->administrator)();
        $form = app(SettingsForm::class)->find($group);

        if ($form === null) {
            Assert::fail("No settings group is named \"{$group}\": a plugin's own group is there only while it runs.");
        }

        $secrets = array_map(strval(...), array_intersect_key($values, $form['secrets']));
        $plain = array_diff_key($values, $form['secrets']);

        $this->acting->as($as, fn (): mixed => app(UpdateSettings::class)->execute(
            new UpdateSettingsInput($group, $plain, [], $secrets, $form['version'], (string) $as->id),
        ), 'Changing the settings in '.$group);

        return $this;
    }

    /**
     * Queues one of the plugin's declared jobs through its own Jobs door, as
     * the person who enabled it. On the test's `sync` queue it runs at once;
     * returns the tracked job as Tools › Jobs records it then.
     *
     * @param  array<string, mixed>  $payload
     */
    public function runJob(string $key, array $payload = []): JobRun
    {
        $record = PluginRecord::query()->where('name', $this->name)->where('state', PluginRecord::ENABLED)->first();
        $manifest = app(PluginRegistry::class)->find($this->name)?->manifest;

        if ($record === null || $manifest === null) {
            Assert::fail("{$this->name} isn't enabled.");
        }

        $person = $this->enabledBy->id ?? $record->consented_by;

        if ($person === null) {
            Assert::fail("{$this->name} was enabled by no person, so its jobs have no one to run as.");
        }

        $doors = app(PluginServices::class)->for($this->name, $this->code, $record->consent['capabilities'] ?? [], Consent::hosts($record->consent, $manifest));
        $jobs = $doors[Jobs::class] ?? null;

        if (! $jobs instanceof Jobs) {
            Assert::fail("{$this->name} wasn't granted jobs.");
        }

        $list = app(TrackedJobList::class);
        $before = array_column($list->ofRun(PluginJobKind::KEY, $key), 'id');
        $signedIn = Auth::user();
        $thrown = null;
        Auth::setUser(User::query()->findOrFail($person));

        try {
            $jobs->queue($key, $payload);
        } catch (Throwable $failure) {
            // On the sync queue a job's own throw reaches whoever queued it, after it is marked failed.
            $thrown = $failure;
        } finally {
            $signedIn === null ? Auth::forgetUser() : Auth::setUser($signedIn);
        }

        $row = null;

        foreach ($list->ofRun(PluginJobKind::KEY, $key) as $candidate) {
            if (! in_array($candidate['id'], $before, true) && $row === null) {
                $row = $candidate;
            }
        }

        if ($row === null) {
            throw $thrown ?? new LogicException("Queueing {$key} recorded no job.");
        }

        $state = in_array($row['state'], [JobRun::FINISHED, JobRun::FAILED, JobRun::CANCELLED], true) ? $row['state'] : JobRun::QUEUED;

        return new JobRun($row['id'], $state, $row['message']);
    }

    /**
     * Runs one pass of the due-schedules runner at this moment, now when
     * none is given, for every enabled plugin; returns how many jobs it queued.
     */
    public function runSchedules(?DateTimeInterface $at = null): int
    {
        return app(DueSchedules::class)->run($at === null ? null : CarbonImmutable::instance($at));
    }

    /**
     * Starts the enabled plugins not yet running, as the next request would,
     * and fails the test when this one doesn't start. One still held from
     * before it was disabled hears its hooks again instead, since what it
     * added to the admin can't be added twice in one process.
     */
    private function start(): void
    {
        $loaded = app(LoadedPlugins::class);

        if (isset($loaded->running()[$this->name])) {
            $this->subscribeAgain($loaded->running()[$this->name]);

            return;
        }

        $before = (string) config('teme.plugins.disabled');

        // Those already running keep running: the server's own switch passes over them.
        config(['teme.plugins.disabled' => implode(',', array_filter([$before, ...array_keys($loaded->running())]))]);

        try {
            app(PluginLoader::class)->load();
        } finally {
            config(['teme.plugins.disabled' => $before]);
        }

        $state = $loaded->state($this->name);

        if ($state !== PluginState::Enabled || $loaded->failure($this->name) !== null) {
            Assert::fail("{$this->name} didn't start: ".($loaded->failure($this->name) ?? $state->value ?? 'the loader never came to it.'));
        }
    }

    /**
     * Subscribes the running plugin's hooks again, as the loader subscribes
     * them when it starts: those its consent allows, less any quarantined
     * for this version.
     */
    private function subscribeAgain(Plugin $plugin): void
    {
        $record = PluginRecord::query()->where('name', $this->name)->sole();
        $manifest = app(PluginRegistry::class)->find($this->name)->manifest ?? Assert::fail("{$this->name} has no teme-plugin.json Teme can read.");
        $quarantined = array_keys(array_filter($record->quarantine, fn (mixed $entry): bool => is_array($entry) && ($entry['version'] ?? null) === $manifest->version()));
        $subscriptions = app(HookSubscriptions::class);

        $subscriptions->forget($this->name);
        $hooks = $subscriptions->for($this->name, $record->consent['capabilities'] ?? [], $manifest->hooks(), $quarantined);

        try {
            $plugin->register($hooks);
        } finally {
            $subscriptions->close($hooks);
        }
    }
}
