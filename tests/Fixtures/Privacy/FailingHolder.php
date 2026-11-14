<?php

declare(strict_types=1);

namespace Tests\Fixtures\Privacy;

use RuntimeException;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * A holder of personal data for the privacy tests that throws on the calls it
 * is told to, counted from 1 across export and erase, and otherwise answers
 * as the holder it wraps.
 */
final class FailingHolder implements PersonalDataHolder
{
    public int $calls = 0;

    /**
     * @param  list<int>  $failOn  the calls that throw
     */
    public function __construct(
        private readonly PersonalDataHolder $inner,
        private readonly array $failOn = [1],
        private readonly string $message = 'The holder broke.',
    ) {}

    public function key(): string
    {
        return $this->inner->key();
    }

    public function label(): string
    {
        return $this->inner->label();
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $this->fail();

        return $this->inner->export($subject, $page);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $this->fail();

        return $this->inner->erase($subject, $page);
    }

    private function fail(): void
    {
        $this->calls++;

        if (in_array($this->calls, $this->failOn, true)) {
            throw new RuntimeException($this->message);
        }
    }
}
