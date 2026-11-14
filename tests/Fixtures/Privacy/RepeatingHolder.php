<?php

declare(strict_types=1);

namespace Tests\Fixtures\Privacy;

use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * A broken holder for the privacy tests: whatever page it is asked for, it
 * answers its first again, with rows found or kept, and never says it is
 * done, so no empty page ever stops it.
 */
final class RepeatingHolder implements PersonalDataHolder
{
    public int $calls = 0;

    public function key(): string
    {
        return 'acme.repeating';
    }

    public function label(): string
    {
        return 'Repeating';
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $this->calls++;

        return new ExportPage([new ExportGroup('Row', ['Name' => 'Ada'])], false);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $this->calls++;

        return new ErasePage(0, 3, ['Kept for the accounts.'], false);
    }
}
