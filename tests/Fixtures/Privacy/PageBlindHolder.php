<?php

declare(strict_types=1);

namespace Tests\Fixtures\Privacy;

use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * A broken export holder for the privacy tests: it ignores the page it is
 * asked for and answers the same large page every time, never done, so its
 * export grows without end unless something stops it.
 */
final class PageBlindHolder implements PersonalDataHolder
{
    public int $calls = 0;

    public function __construct(private readonly int $bytes = 4096) {}

    public function key(): string
    {
        return 'acme.blind';
    }

    public function label(): string
    {
        return 'Blind';
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $this->calls++;

        return new ExportPage([new ExportGroup('Row', ['Text' => str_repeat('a', $this->bytes)])], false);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        return new ErasePage(0, 0, [], true);
    }
}
