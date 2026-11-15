<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Operations\Concerns\MakesLocalActors;
use App\Domain\Fediverse\Operations\Input\SwitchFediverseInput;
use App\Domain\Settings\Modules;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

/**
 * Switches the fediverse on or off (docs/plans/fediverse-actors.md, D6): the
 * `modules` group's switch, at the top of Settings › Fediverse, for whoever
 * holds settings.modules.manage. Switched on, the blog's and the
 * application's accounts are made with their keys if the site has none;
 * off, it is paused and keeps them. Every page is purged after commit, as
 * the group declares.
 */
final class SwitchFediverse extends BaseOperation
{
    use MakesLocalActors;

    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SwitchFediverseInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('settings.modules.manage')) {
            throw new AuthorizationException('Not permitted to switch the fediverse on or off.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SwitchFediverseInput $input */
        $setting = $this->registry->find('modules')?->setting(Modules::FEDIVERSE);
        $rules = $setting === null ? [] : SettingRules::for($setting);

        Validator::make(['values' => ['fediverse' => $input->value]], ['values.fediverse' => ['required', ...$rules]])->validate();
    }

    /**
     * @return array{on: bool, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SwitchFediverseInput $input */
        $was = FediverseSwitch::on();

        $this->writer->write(new SettingsWrite(
            group: 'modules',
            values: [Modules::FEDIVERSE => $input->value],
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));

        $on = $input->value === Modules::ON;

        if ($on) {
            $this->makeSiteActors();
        }

        return ['on' => $on, 'changed' => $on !== $was];
    }

    /**
     * The blog's and the application's accounts, each made once: an
     * imported blog account is kept as it is.
     */
    private function makeSiteActors(): void
    {
        $base = rtrim(url('/'), '/').'/'.ActivityPub::PREFIX;

        if (! FediverseActor::query()->where('kind', FediverseActor::BLOG)->exists()) {
            $this->makeLocalActor(FediverseActor::BLOG, 'blog', $base.'/blog', FediverseSettingsGroup::blogFollowable());
        }

        if (! FediverseActor::query()->where('kind', FediverseActor::APPLICATION)->exists()) {
            $host = (string) (parse_url(url('/'), PHP_URL_HOST) ?: 'localhost');
            $this->makeLocalActor(FediverseActor::APPLICATION, $host, $base.'/application');
        }
    }
}
