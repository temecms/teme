<?php

/**
 * A request whose answer must take the shape its types describe (I-316);
 * laravel/ai sends them as the service's structured output.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\HasStructuredOutput;

final class StructuredRequestAgent extends RequestAgent implements HasStructuredOutput
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->types;
    }
}
