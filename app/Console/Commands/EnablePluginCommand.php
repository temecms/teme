<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\Plugins\DeclaredAiTask;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Plugins\Area\PluginPermissions;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Dependencies;
use App\Domain\Plugins\Jobs\ScheduleRunner;
use App\Domain\Plugins\Notices\DeclaredNotices;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginSentences;
use App\Domain\Plugins\PluginWords;
use App\Domain\Plugins\SettingHosts;
use App\Domain\PublicRoutes\RootFile;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Plugins\Capability;

/**
 * `teme:plugins:enable`: enables a plugin as the operator (I-3353), saying
 * first why it can't be used, if it can't, or else what it asks to do, and
 * enabling it only once run again with --agree. A plugin with schedules
 * needs --as, the administrator they run as (docs/proposals/plugin-sockets-1.md).
 */
final class EnablePluginCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:enable {name : The plugin’s Composer name} {--agree : Agree to what it asks for} {--as= : The email of the administrator its schedules run as}';

    /** @var string */
    protected $description = 'Enable a plugin, agreeing to what it asks for';

    /**
     * Runs with core's own words only: a plugin's language file never
     * re-words what the operator agrees to (docs/plans/plugin-sockets-1.md, A-W14).
     */
    public function handle(PluginRegistry $registry, EnablePlugin $enable, PluginSentences $sentences, ScheduleRunner $schedules): int
    {
        return $sentences->coreOnly(fn (): int => $this->enable($registry, $enable, $schedules));
    }

    private function enable(PluginRegistry $registry, EnablePlugin $enable, ScheduleRunner $schedules): int
    {
        $name = (string) $this->argument('name');
        $plugin = $registry->find($name);

        if ($plugin === null) {
            $this->components->error(__('No plugin is named ":name".', ['name' => $name]));

            return self::FAILURE;
        }

        if (! $plugin->usable()) {
            $this->components->error($plugin->problems[0] ?? '');

            return self::FAILURE;
        }

        $manifest = $plugin->manifest;
        $label = $manifest?->label() ?? $name;
        $version = $manifest?->version() ?? '';
        $capabilities = $manifest?->capabilities() ?? [];
        $hosts = $manifest?->hosts() ?? [];
        $aiTasks = $manifest === null ? [] : PluginAiTasks::of($manifest);
        $fromSettings = $manifest === null ? [] : SettingHosts::declared($manifest);
        $settingHosts = $manifest === null || $fromSettings === [] ? [] : app(SettingHosts::class)->current($manifest);
        $http = $manifest === null || $fromSettings === []
            ? __('Connect to services outside this site: :hosts', ['hosts' => implode(', ', $hosts)])
            : PluginWords::httpWithSettings($hosts, SettingHosts::labels($manifest));

        $this->line(__(':label :version asks to:', ['label' => $label, 'version' => $version]));

        foreach ($capabilities as $capability) {
            $this->line('  '.match ($capability) {
                Capability::ContentRead->value => __('Read your posts and pages, including drafts'),
                Capability::MediaRead->value => __('Read the media library'),
                Capability::ContentInsert->value => __('Add content inside your articles, such as ads'),
                Capability::PagesInsert->value => __('Add content to every page of your site: in the head, at the top and bottom of the page, between items on lists, in the theme’s regions, and fixed to the screen’s edge'),
                Capability::ContentLinks->value => __('Change how links in your articles behave: nofollow, new tab'),
                Capability::ContentWrite->value => __('Create and change posts, pages and other content, for the person using it'),
                Capability::MediaWrite->value => __('Add files to the media library and change their details, for the person using it'),
                Capability::Jobs->value => __('Run work in the background and on a schedule'),
                Capability::RoutesPublic->value => __('Answer addresses on the public site without anyone signing in'),
                Capability::FediverseRead->value => __('See who follows the site on the fediverse, and hear what they send it'),
                Capability::FediverseSend->value => __('Send activity to the site’s fediverse followers'),
                Capability::FeedsLinks->value => __('Add links to your site’s feeds, such as the hub feed readers subscribe through'),
                Capability::PeopleRead->value => __('See the names of the people who write on this site'),
                Capability::NoticesSend->value => PluginWords::capability($capability, notices: array_column(DeclaredNotices::of($manifest), 'label')),
                Capability::ImportWordPress->value => __('Receive the data it declares from the WordPress site you import'),
                Capability::Http->value => $http,
                Capability::Ai->value => PluginWords::capability($capability, aiTasks: array_map(fn (DeclaredAiTask $task): string => $task->label, $aiTasks)),
                default => $capability,
            });

            if ($capability === Capability::Ai->value) {
                foreach ($aiTasks as $task) {
                    $this->line('    '.PluginWords::aiTask($task));
                }
            }

            // Each file it answers at the site's root, as the consent lists it (docs/guides/developers/plugins/root-files.md).
            if ($capability === Capability::RoutesPublic->value) {
                foreach (RootFile::declaredIn($manifest) as $file) {
                    $this->line('    '.$file->consentLine($manifest));
                }
            }

            if ($capability === Capability::Http->value && $fromSettings !== []) {
                $this->line('    '.PluginWords::settingHostsNow($settingHosts));
            }
        }

        $insertionHosts = $manifest === null ? [] : array_values(array_unique(array_merge(...array_values(Consent::declaredInsertionHosts($manifest)))));

        if ($insertionHosts !== []) {
            $this->line('  '.PluginWords::insertionHosts($insertionHosts));
        }

        foreach ($manifest === null ? [] : PluginPermissions::consentWords($manifest) as $words) {
            $this->line('  '.$words);
        }

        foreach (array_keys($manifest === null ? [] : Dependencies::required($manifest)) as $required) {
            $this->line('  '.PluginWords::needs($required));
        }

        if (! $this->option('agree')) {
            $this->line(__('Run it again with --agree to enable it with these.'));

            return self::FAILURE;
        }

        // Scheduled work runs as an administrator, never as the operator: refused before anything is written (docs/proposals/plugin-sockets-1.md).
        $email = $this->option('as');
        $schedulesAs = null;

        if (is_string($email) && $email !== '') {
            $schedulesAs = $schedules->administrator($email);

            if ($schedulesAs === null) {
                $this->components->error(__('No one who may manage plugins has the email :email.', ['email' => $email]));

                return self::FAILURE;
            }
        } elseif ($schedules->declaresSchedules($name)) {
            $this->components->error(__(':label runs work on a schedule, and scheduled work runs as an administrator. Name one with --as=<email>.', ['label' => $label]));

            return self::FAILURE;
        }

        // The operator (docs/architecture/operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            $enable->execute(new EnablePluginInput($name, $version, $capabilities, $hosts, $manifest?->hooks() ?? [], null, settingHosts: $settingHosts, schedulesAs: $schedulesAs));
        } catch (ValidationException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__(':label enabled.', ['label' => $label]));

        return self::SUCCESS;
    }
}
