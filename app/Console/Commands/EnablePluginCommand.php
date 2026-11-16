<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Jobs\ScheduleRunner;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
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

    public function handle(PluginRegistry $registry, EnablePlugin $enable, ScheduleRunner $schedules): int
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

        $this->line(__(':label :version asks to:', ['label' => $label, 'version' => $version]));

        foreach ($capabilities as $capability) {
            $this->line('  '.match ($capability) {
                Capability::ContentRead->value => __('Read your posts and pages, including drafts'),
                Capability::MediaRead->value => __('Read the media library'),
                Capability::ContentInsert->value => __('Add content inside your articles, such as ads'),
                Capability::ContentWrite->value => __('Create and change posts, pages and other content, for the person using it'),
                Capability::MediaWrite->value => __('Add files to the media library and change their details, for the person using it'),
                Capability::Jobs->value => __('Run work in the background and on a schedule'),
                Capability::RoutesPublic->value => __('Answer addresses on the public site without anyone signing in'),
                Capability::FediverseRead->value => __('See who follows the site on the fediverse, and hear what they send it'),
                Capability::FediverseSend->value => __('Send activity to the site’s fediverse followers'),
                Capability::ImportWordPress->value => __('Receive the data it declares from the WordPress site you import'),
                Capability::Http->value => __('Connect to services outside this site: :hosts', ['hosts' => implode(', ', $hosts)]),
                default => $capability,
            });
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
            $enable->execute(new EnablePluginInput($name, $version, $capabilities, $hosts, $manifest?->hooks() ?? [], null, schedulesAs: $schedulesAs));
        } catch (ValidationException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__(':label enabled.', ['label' => $label]));

        return self::SUCCESS;
    }
}
