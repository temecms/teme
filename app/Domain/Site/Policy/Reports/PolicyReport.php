<?php

declare(strict_types=1);

namespace App\Domain\Site\Policy\Reports;

/**
 * One policy violation a browser reported (I-3375), reduced to what Teme
 * keeps: the directive, the blocked origin or keyword, the page's path, how
 * the policy was sent, and for an inline script or eval its short sample.
 */
final readonly class PolicyReport
{
    /** The directives a report may name: the fetch and document directives CSP3 lists. */
    public const array DIRECTIVES = [
        'default-src', 'script-src', 'script-src-elem', 'script-src-attr',
        'style-src', 'style-src-elem', 'style-src-attr', 'img-src', 'media-src',
        'font-src', 'frame-src', 'child-src', 'worker-src', 'connect-src',
        'manifest-src', 'object-src', 'base-uri', 'form-action', 'frame-ancestors',
    ];

    /**
     * @param  array{sample?: string, source?: string, line?: int, column?: int}|null  $sample
     */
    public function __construct(
        public string $directive,
        public string $blocked,
        public string $path,
        public string $disposition,
        public ?array $sample,
        public int $at,
    ) {}
}
