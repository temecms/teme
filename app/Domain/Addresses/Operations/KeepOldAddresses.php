<?php

/**
 * Keeps addresses another site gave things as their old addresses (I-061):
 * each a history row of the path table pointing at its item, term or author
 * by ID, so it answers 301 to wherever that is now, follows it when it
 * moves, and never chains. A path the table already holds is left as it is,
 * and named back.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses\Operations;

use App\Domain\Addresses\Operations\Input\KeepOldAddressesInput;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageTags;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Redirects\RedirectSource;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class KeepOldAddresses extends BaseOperation
{
    use AuthorizesImports;

    /** The targets an old address may lead to, and their tables: the kinds of Paths::KINDS but a type's archive page. */
    private const array TARGETS = ['content' => 'contents', 'term' => 'terms', 'user' => 'users', 'person' => 'people'];

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var KeepOldAddressesInput $input */
        $count = count($input->addresses);

        if ($count < 1 || $count > 1000) {
            throw ValidationException::withMessages(['addresses' => 'Keep 1 to 1,000 old addresses at a time.']);
        }

        foreach ($input->addresses as $index => $address) {
            $path = $address['path'];
            $table = self::TARGETS[$address['type']] ?? null;

            if ($path === '' || $path !== PublicRequestPath::normalise($path) || mb_strlen($path) > RedirectSource::LONGEST || ! RedirectSource::readable($path)) {
                throw ValidationException::withMessages(["addresses.{$index}" => 'An old address is a path in the path table’s form, of 1 to 2,000 characters.']);
            }

            if (Paths::taken(explode('/', $path)[0])) {
                throw ValidationException::withMessages(["addresses.{$index}" => __('The address :address is used by the site itself.', ['address' => '/'.$path.'/'])]);
            }

            // An account's person has no page of its own: its account's is (I-3366).
            if ($table === null || ! DB::table($table)->where('id', $address['id'])->when($table === 'people', fn ($query) => $query->whereNull('user_id'))->exists()) {
                throw ValidationException::withMessages(["addresses.{$index}" => 'An old address leads to an item, a term or an author that is here.']);
            }
        }
    }

    /**
     * @return array{kept: list<string>, held: list<array{path: string, current: bool, type: string, id: string}>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var KeepOldAddressesInput $input */
        $kept = [];
        $held = [];

        foreach ($input->addresses as $address) {
            // A live address, or another's old one, comes first: ON CONFLICT DO NOTHING.
            $inserted = DB::table('paths')->insertOrIgnore([
                'path' => $address['path'],
                'target_type' => $address['type'],
                'target_id' => $address['id'],
                'is_current' => false,
                'created_at' => now(),
                'replaced_at' => now(),
            ]);

            if ($inserted === 1) {
                $kept[] = $address['path'];

                continue;
            }

            $row = DB::table('paths')->where('path', $address['path'])->first(['is_current', 'target_type', 'target_id']);

            if ($row !== null) {
                $held[] = ['path' => $address['path'], 'current' => (bool) $row->is_current, 'type' => (string) $row->target_type, 'id' => (string) $row->target_id];
            }
        }

        if ($kept !== []) {
            // A "not found" page stored at an old address goes: it answers now.
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'paths', array_map(fn (string $path): string => PageTags::url('/'.$path), $kept)));
        }

        return ['kept' => $kept, 'held' => $held];
    }
}
