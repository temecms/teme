<?php

declare(strict_types=1);

namespace App\Domain\Addresses\Operations\Concerns;

use App\Domain\Addresses\AddressConflicts;
use App\Domain\Addresses\AddressPattern;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Addresses\Operations\Input\ImportAddressesInput;
use App\Domain\Addresses\Paths;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The checks on a change of the address settings, from Settings › Addresses
 * or Reading or an import (I-393), one rule for all: each pattern, base,
 * the author base and each archive page's address Teme can write, a published
 * page for the home page, and no two things at one address.
 */
trait ChecksAddressSettings
{
    /**
     * Refuses a change Settings › Addresses would refuse: under each field's
     * own key, or `addresses.N` for each clash, every one listed.
     *
     * @throws ValidationException
     */
    private function checkAddressSettings(ChangeAddressesInput|ImportAddressesInput $input): void
    {
        $current = AddressSettings::current();
        $problems = [];

        foreach ($input->patterns as $type => $pattern) {
            if (! $current->hasPattern($type)) {
                $problems["patterns.{$type}"] = __('There is no public type [:type].', ['type' => $type]);

                continue;
            }

            try {
                $parsed = AddressPattern::parse($pattern);
            } catch (InvalidArgumentException $exception) {
                $problems["patterns.{$type}"] = $exception->getMessage();

                continue;
            }

            if (Paths::taken($parsed->firstFixedSegment())) {
                $problems["patterns.{$type}"] = __('Addresses starting /:segment/ are used by the site itself.', ['segment' => $parsed->firstFixedSegment()]);
            } elseif (in_array(AddressPattern::PARENT, $parsed->tokens(), true) && ! app(ContentTypeRegistry::class)->get($type)->hierarchical) {
                // Only a type whose items have a parent has parents' slugs to show (I-3363).
                $problems["patterns.{$type}"] = __("{parent} is only for types whose items have a parent. :plural don't.", ['plural' => app(ContentTypeRegistry::class)->get($type)->plural]);
            }
        }

        foreach ($input->bases as $taxonomy => $base) {
            if (! $current->hasBase($taxonomy)) {
                $problems["bases.{$taxonomy}"] = __('There is no public taxonomy [:taxonomy].', ['taxonomy' => $taxonomy]);
            } elseif (preg_match('/^[a-z0-9-]*$/', $base) !== 1) {
                $problems["bases.{$taxonomy}"] = __('Use lower-case letters, numbers and hyphens only.');
            } elseif (Paths::taken($base)) {
                $problems["bases.{$taxonomy}"] = __('Addresses starting /:segment/ are used by the site itself.', ['segment' => $base]);
            }
        }

        foreach ($input->archives as $type => $address) {
            if (! $current->hasArchive($type)) {
                $problems["archives.{$type}"] = __('There is no archive page for [:type].', ['type' => $type]);
            } elseif (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $address) !== 1) {
                $problems["archives.{$type}"] = __('An archive page’s address needs lower-case letters, numbers, hyphens or underscores.');
            } elseif (preg_match('/^(?:\d+|page)$/', $address) === 1) {
                // A year's archive, and a list's later pages, read these first (app/Domain/Site/PathResolver.php).
                $problems["archives.{$type}"] = __('An archive page’s address can’t be a number or “page”: dates and a list’s later pages use them.');
            } elseif (Paths::taken($address)) {
                $problems["archives.{$type}"] = __('Addresses starting /:segment/ are used by the site itself.', ['segment' => $address]);
            }
        }

        if ($input->authorBase !== null) {
            if (preg_match('/^[a-z0-9-]+$/', $input->authorBase) !== 1) {
                $problems['authorBase'] = __('The author base needs lower-case letters, numbers or hyphens.');
            } elseif (Paths::taken($input->authorBase)) {
                $problems['authorBase'] = __('Addresses starting /:segment/ are used by the site itself.', ['segment' => $input->authorBase]);
            }
        }

        // The home page is checked when this change sends any of it: Reading
        // sends it, Settings › Addresses doesn't.
        if ($input->frontShows !== null || $input->frontPageId !== null || $input->postsPageId !== null) {
            $frontShows = $input->frontShows ?? $current->frontShows;
            $frontPageId = $input->frontPageId ?? $current->frontPageId;
            $postsPageId = $input->postsPageId ?? $current->postsPageId;

            if (! in_array($frontShows, [AddressSettings::FRONT_LATEST, AddressSettings::FRONT_PAGE], true)) {
                $problems['frontShows'] = __('Choose the latest posts or a page.');
            } elseif ($frontShows === AddressSettings::FRONT_PAGE) {
                if (! $this->isPublishedPage($frontPageId)) {
                    $problems['frontPageId'] = __('Choose a published page for the home page.');
                }

                if ($postsPageId !== '' && ! $this->isPublishedPage($postsPageId)) {
                    $problems['postsPageId'] = __('Choose a published page for the posts page.');
                }

                if ($postsPageId !== '' && $postsPageId === $frontPageId) {
                    $problems['postsPageId'] = __('The home page and the posts page must be different pages.');
                }
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        // Only an archive page this change moves is checked for clashes; one
        // that stays waits behind whatever holds its address (I-3304).
        $moving = [];

        foreach ($input->archives as $type => $address) {
            if ($current->hasArchive($type) && trim($address) !== $current->archives[$type]) {
                $moving[] = $type;
            }
        }

        $clashes = AddressConflicts::check($this->next($current, $input), $moving);

        // One key each, so the screen can list every clash, not the first.
        if ($clashes !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(fn (int $i): string => "addresses.{$i}", array_keys($clashes)),
                $clashes,
            ));
        }
    }

    /**
     * The settings after this change: a null field, or a type or taxonomy
     * left out, keeps its value.
     */
    private function next(AddressSettings $current, ChangeAddressesInput|ImportAddressesInput $input): AddressSettings
    {
        return $current->with(
            array_map('trim', $input->patterns),
            array_map('trim', $input->bases),
            trim($input->authorBase ?? $current->authorBase),
            $input->frontShows ?? $current->frontShows,
            $input->frontPageId ?? $current->frontPageId,
            $input->postsPageId ?? $current->postsPageId,
            array_map('trim', $input->archives),
        );
    }

    /**
     * A published, live, public item of a public type with no archive: a page,
     * as the home page or the posts page must be (I-3298). No type is named.
     */
    private function isPublishedPage(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        $row = DB::table('contents')->where('id', $id)->whereNull('deleted_at')->where('status', 'published')->where('visibility', 'public')->first(['type']);
        $types = app(ContentTypeRegistry::class);

        return $row !== null
            && $types->has((string) $row->type)
            && $types->get((string) $row->type)->public
            && ! $types->get((string) $row->type)->archive;
    }
}
