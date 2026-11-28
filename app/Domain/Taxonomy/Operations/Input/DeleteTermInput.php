<?php

/**
 * What DeleteTerm needs: which term, who is asking, or a plugin's code when
 * it removes a term of a taxonomy it owns as the site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DeleteTermInput implements OperationInput
{
    /**
     * @param  string|null  $plugin  a plugin's code when it removes as the site
     * @param  string|null  $pluginName  that plugin's Composer name, for ownership
     */
    public function __construct(
        public string $termId,
        public ?string $actorId,
        public ?string $plugin = null,
        public ?string $pluginName = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
