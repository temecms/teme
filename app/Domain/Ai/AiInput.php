<?php

/**
 * What a task's input builder gives the prompt: named fields of text, each
 * fenced on its way (AiPrompt), and the images that go with them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

final readonly class AiInput
{
    /**
     * @param  array<string, string>  $fields
     * @param  list<AiImage>  $images
     */
    public function __construct(public array $fields, public array $images = []) {}
}
