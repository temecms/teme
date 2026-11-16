<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\HostPattern;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Input\AgreePluginHostsInput;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Lets a plugin reach hosts a person entered in the settings its manifest
 * takes hosts from (docs/plans/plugin-sockets-1.md, A2): only a person who
 * holds plugins.manage, after their password, never the plugin itself. Adds
 * the hosts to what its consent agrees in its settings, and records who
 * agreed to which, and when. Returns the hosts agreed in its settings.
 */
final class AgreePluginHosts extends BaseOperation implements RequiresElevation
{
    public function __construct(private readonly PluginRecords $records) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function authorize(OperationInput $input): void
    {
        $actorId = $input->actorId();

        if ($actorId === null || app(Actors::class)->find($actorId)?->can(PluginAccess::MANAGE) !== true) {
            throw new AuthorizationException;
        }
    }

    protected function validate(OperationInput $input): void
    {
        assert($input instanceof AgreePluginHostsInput);

        foreach ($input->hosts as $host) {
            if (preg_match(HostPattern::PATTERN, $host) !== 1 || str_starts_with($host, '*.')) {
                throw ValidationException::withMessages(['hosts' => [__(':host isn’t a host name.', ['host' => $host])]]);
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var AgreePluginHostsInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['plugin' => [__('No plugin is named ":name".', ['name' => $input->name])]]);

        $consent = $record->consent;
        $agreed = array_values(array_filter((array) ($consent['settingHosts'] ?? []), is_string(...)));
        $new = array_values(array_diff(array_unique($input->hosts), $agreed));

        if ($new === []) {
            return $agreed;
        }

        $consent['settingHosts'] = [...$agreed, ...$new];
        $consent['settingHostsAgreed'] = [
            ...(is_array($consent['settingHostsAgreed'] ?? null) ? array_values($consent['settingHostsAgreed']) : []),
            ['hosts' => $new, 'by' => (int) $input->actorId(), 'at' => CarbonImmutable::now()->toIso8601String()],
        ];
        $record->consent = $consent;
        $record->save();

        DB::afterCommit(fn () => $this->records->changed());

        return $consent['settingHosts'];
    }
}
