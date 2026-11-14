<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Closure;
use Teme\Contracts\Operations\OperationInput;

/**
 * What AnnouncingOperation announces, whether it runs another inside it,
 * fails after it, or is refused; and a further notification to build.
 */
final readonly class AnnouncingInput implements OperationInput
{
    /**
     * @param  (Closure(): Pinged)|null  $build  announced as Pinged before the one for $what
     * @param  list<string>  $purges  page-cache tags it tells the page cache of
     */
    public function __construct(
        public string $what = 'x',
        public ?AnnouncingInput $inner = null,
        public bool $fail = false,
        public bool $refuse = false,
        public ?Closure $build = null,
        public array $purges = [],
    ) {}

    public function actorId(): ?string
    {
        return null;
    }
}
