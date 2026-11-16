<?php

declare(strict_types=1);

namespace Teme\Contracts\Privacy;

/**
 * One place that keeps personal data, answering export and erase requests a
 * page at a time, so a holder with thousands of rows never runs long. Core's
 * holders and plugins' are asked the same way. A preview until Teme 1.0.
 *
 * @since 0.5.0
 */
interface PersonalDataHolder
{
    /**
     * A key unique across the site: lower-case words, hyphens inside, dots
     * between; a plugin's starts with its code and a dot.
     *
     * @since 0.5.0
     */
    public function key(): string;

    /**
     * What it holds, for the person and the operator: "Comments".
     *
     * @since 0.5.0
     */
    public function label(): string;

    /**
     * One page of what it holds about the subject, from 1. Done once a page
     * holds the last of it; an empty page that is done is fine.
     *
     * @since 0.5.0
     */
    public function export(PersonalDataSubject $subject, int $page): ExportPage;

    /**
     * Erases or keeps one page of what it holds about the subject, from 1.
     * A holder whose erased rows stop matching may answer page 1 again each
     * time. Done once nothing is left to erase or keep; never throws for
     * something it keeps: it counts it and says why.
     *
     * @since 0.5.0
     */
    public function erase(PersonalDataSubject $subject, int $page): ErasePage;
}
