<?php

/**
 * A language to add to the site: its tag, its address prefix, whether
 * readers are shown it now, the languages' version the form was opened on,
 * and who asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AddSiteLanguageInput implements OperationInput
{
    public function __construct(
        public string $tag,
        public string $prefix,
        public bool $shown,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
