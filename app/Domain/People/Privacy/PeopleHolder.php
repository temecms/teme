<?php

declare(strict_types=1);

namespace App\Domain\People\Privacy;

use App\Domain\Addresses\Addresses;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\People\Queries\People;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * An account's byline: exported as their author page shows it, and kept on
 * erasure while anything credits the account, by the rule `DeleteUser`
 * keeps a departed writer by, since a byline is the site's record of who
 * wrote what (I-3366).
 */
final class PeopleHolder implements PersonalDataHolder
{
    public function __construct(private readonly People $people, private readonly Credits $credits) {}

    public function key(): string
    {
        return 'people';
    }

    public function label(): string
    {
        return __('Bylines');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $credit = $subject->accountId === null ? null : Credit::account((int) $subject->accountId);

        if ($credit === null || (! $this->credits->creditsAccount((int) $credit->id) && $this->people->personOf((int) $credit->id) === null)) {
            return new ExportPage([], true);
        }

        $shown = $this->people->shown([$credit])[$credit->key()] ?? null;

        return new ExportPage([new ExportGroup(__('Your byline'), array_filter([
            __('Name') => (string) ($shown->name ?? ''),
            __('Author page') => $shown?->path === null ? '' : rtrim(url('/'), '/').Addresses::toUrl((string) $shown->path),
            __('Biography') => (string) ($shown->bio ?? ''),
            __('Items that credit you') => (string) $this->credits->itemCount($credit),
        ], fn (string $value): bool => $value !== ''))], true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $userId = $subject->accountId === null ? null : (int) $subject->accountId;

        // Nothing is written. Credited by the rule `DeleteUser` keeps a writer by, the name stays when the account goes (issue 396); a person nothing credits goes with it.
        if ($userId !== null && $this->credits->creditsAccount($userId)) {
            return new ErasePage(0, 1, [__('Your name stays on what you’re credited on, as the record of who wrote it, even after the account is deleted. The site’s administrators can take it off.')], true);
        }

        return $userId !== null && $this->people->personOf($userId) !== null
            ? new ErasePage(0, 1, [__('This goes when the account is deleted.')], true)
            : new ErasePage(0, 0, [], true);
    }
}
