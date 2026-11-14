<?php

declare(strict_types=1);

namespace Tests\Fixtures\Privacy;

use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * A holder of personal data for the privacy tests: it answers the pages it is
 * given, in turn, and records every call it gets as [what, email, account,
 * page]. Past its last page it answers an empty page that is done.
 */
final class RecordingHolder implements PersonalDataHolder
{
    /** @var list<array{0: string, 1: string, 2: string|null, 3: int}> */
    public array $calls = [];

    /**
     * @param  list<ExportPage>  $exports
     * @param  list<ErasePage>  $erasures
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $exports = [],
        private readonly array $erasures = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $this->calls[] = ['export', $subject->email, $subject->accountId, $page];

        return $this->exports[$page - 1] ?? new ExportPage([], true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $this->calls[] = ['erase', $subject->email, $subject->accountId, $page];

        return $this->erasures[$page - 1] ?? new ErasePage(0, 0, [], true);
    }
}
