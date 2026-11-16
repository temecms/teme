<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportNavigation needs: the menu an earlier run made (null for a new
 * one), WordPress's name for it, its whole tree as SaveNavigationItems takes
 * it, the fingerprint the import last left (null to check nothing), and who
 * is importing (null for the operator), and, for a new menu, the site
 * language it is written in (null for the default).
 */
final readonly class ImportNavigationInput implements OperationInput
{
    /**
     * @param  array<mixed>  $tree  the whole tree, as SaveNavigationItems takes it
     */
    public function __construct(
        public ?string $navigationId,
        public string $name,
        public array $tree,
        public ?string $expectedFingerprint,
        public ?string $actorId,
        public ?string $language = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
