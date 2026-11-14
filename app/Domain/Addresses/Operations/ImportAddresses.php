<?php

declare(strict_types=1);

namespace App\Domain\Addresses\Operations;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\Operations\Concerns\ChecksAddressSettings;
use App\Domain\Addresses\Operations\Input\ImportAddressesInput;
use App\Domain\Addresses\PathProjection;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Facades\Cache;
use Teme\Contracts\Operations\OperationInput;

/**
 * Sets the address settings an import of another site brings (I-393), with
 * the checks Settings › Addresses makes, for the operator or a person who
 * may import, recorded as the import's, including each type's archive page's
 * address; after commit, every address that moves is moved in the background,
 * the old ones kept as history.
 */
final class ImportAddresses extends BaseOperation
{
    use AuthorizesImports;
    use ChecksAddressSettings;

    public function __construct(private readonly SettingsWriter $writer) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportAddressesInput $input */
        $this->checkAddressSettings($input);
    }

    /**
     * @return array{recompute: bool, actorId: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportAddressesInput $input */
        // Two saves never write old addresses from the same settings (I-412):
        // the row is written first, so a site's first two saves wait too.
        SettingsGroupRecord::query()->insertOrIgnore(['group_key' => 'addresses', 'overrides' => '{}', 'version' => 0]);
        SettingsGroupRecord::query()->whereKey('addresses')->lockForUpdate()->first();
        Cache::memo()->forget(SettingsRepository::cacheKey('addresses'));
        app()->forgetInstance(AddressSettings::class);
        $current = AddressSettings::current();
        $next = $this->next($current, $input);
        $values = [
            'author_base' => $next->authorBase,
            'front_shows' => $next->frontShows,
            'front_page_id' => $next->frontPageId,
            'posts_page_id' => $next->postsPageId,
        ];

        foreach ($next->patterns as $type => $pattern) {
            $values['pattern_'.$type] = $pattern;
        }

        foreach ($next->bases as $taxonomy => $base) {
            $values['base_'.$taxonomy] = $base;
        }

        foreach ($next->archives as $type => $address) {
            $values['archive_'.$type] = $address;
        }

        $this->writer->write(new SettingsWrite(group: 'addresses', values: $values, actorId: $input->actorId(), origin: 'import'));

        // Each archive page that moved keeps its old address (I-412).
        app(PathProjection::class)->archivesMoved($current->archives, $next->archives);

        // Anything an address is made of: every pattern and base, the author base, and which page is the home page.
        $moved = $next->patterns !== $current->patterns
            || $next->bases !== $current->bases
            || $next->authorBase !== $current->authorBase
            || $next->frontShows !== $current->frontShows
            || $next->frontPageId !== $current->frontPageId;

        return ['recompute' => $moved, 'actorId' => $input->actorId()];
    }

    /**
     * After commit, in this order: forget this job's settings, then queue
     * the recompute, which must read the new ones.
     */
    protected function afterCommit(mixed $result): void
    {
        /** @var array{recompute: bool, actorId: string|null} $result */
        app()->forgetInstance(AddressSettings::class);

        if ($result['recompute']) {
            RecomputeAddresses::dispatch(JobActor::of($result['actorId']));
        }
    }
}
