<?php

/**
 * Changes the fediverse group on Settings › Fediverse, against the version
 * the page was opened on, for whoever holds settings.fediverse.manage. Who
 * can be followed takes effect at once: the blog's account answers or
 * doesn't, and authors' accounts stop answering while only the blog can be
 * followed, keeping their followers. Returns the group's new version.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Operations\Input\ChangeFediverseSettingsInput;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;

final class ChangeFediverseSettings extends BaseOperation
{
    use AuthorizesFediverseWork;

    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFediverseWork($input, 'Not permitted to change the fediverse settings.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeFediverseSettingsInput $input */
        $group = $this->registry->find(FediverseSettingsGroup::KEY) ?? throw new RuntimeException('No settings group [fediverse] is registered.');

        SettingRules::validateValues($group, $input->values);
    }

    protected function perform(OperationInput $input): int
    {
        /** @var ChangeFediverseSettingsInput $input */
        $version = $this->writer->write(new SettingsWrite(
            group: FediverseSettingsGroup::KEY,
            values: $input->values,
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));

        // The new value, not the reader's: its cache is forgotten after commit.
        $followable = (string) ($input->values[FediverseSettingsGroup::FOLLOWABLE] ?? FediverseSettingsGroup::followable());

        FediverseActor::query()->where('kind', FediverseActor::BLOG)->update(['enabled' => $followable !== FediverseSettingsGroup::AUTHORS, 'updated_at' => now()]);

        if ($followable === FediverseSettingsGroup::BLOG) {
            FediverseActor::query()->where('kind', FediverseActor::PERSON)->where('enabled', true)->update(['enabled' => false, 'updated_at' => now()]);
        }

        return $version;
    }
}
