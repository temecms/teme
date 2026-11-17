<?php

declare(strict_types=1);

use App\Domain\Ai\AiSettings;
use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Teme\Contracts\Ai\SiteAi;
use Tests\Fixtures\Plugins\PluginFixtures;

// The scribe fixture plugin's helpers for the plugin AI tests (docs/plans/plugin-sockets-3.md, Part A).

/**
 * Enables the scribe fixture (or a copy of it at $folder), as the operator,
 * and builds Settings › AI again so its Plugins section has the plugin's rows.
 */
function scribeEnable(string $folder = 'scribe'): void
{
    $previous = app(Elevation::class);
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use($folder);
    PluginFixtures::installed(['tests/scribe' => $folder]);
    $manifest = app(PluginRegistry::class)->find('tests/scribe')?->manifest ?? throw new RuntimeException('The scribe fixture is not found.');

    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/scribe', $manifest->version(), $manifest->capabilities(), [], [], null));
    app()->instance(Elevation::class, $previous);
    scribeSettings();
}

/**
 * Builds the `ai` group again from the plugins enabled now.
 */
function scribeSettings(): void
{
    app(SettingsRegistry::class)->replace(app(AiSettings::class)->group());
}

/**
 * Chooses a scribe task's model and fallback as stored settings.
 */
function scribeChoose(string $task, string $model, string $fallback = ''): void
{
    cmSettings([PluginAiSection::modelKey('scribe', $task) => $model, PluginAiSection::fallbackKey('scribe', $task) => $fallback], AiSettings::KEY);
}

/**
 * The SiteAi door scribe is given, for an ordinary run or a long job's.
 */
function scribeDoor(bool $long = false): SiteAi
{
    $door = app(PluginDoors::class)->for('tests/scribe', 'scribe', ['ai', 'jobs'], [], long: $long)[SiteAi::class] ?? null;

    return $door instanceof SiteAi ? $door : throw new RuntimeException('Scribe was given no SiteAi door.');
}

/**
 * Runs what follows as a job queued by this person, or the operator's.
 */
function scribeRunAs(?User $person): void
{
    if ($person === null) {
        app()->instance(Elevation::class, new OperatorElevation);
    }

    app(CurrentJobActor::class)->set($person === null ? JobActor::operator() : JobActor::person($person->id));
}
