<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * One message in a request: the system's instructions or the user's fenced
 * material (AiPrompt). Teme sends no assistant turns: there is no conversation.
 */
final readonly class AiMessage
{
    private function __construct(public string $role, public string $text) {}

    public static function system(string $text): self
    {
        return new self('system', $text);
    }

    public static function user(string $text): self
    {
        return new self('user', $text);
    }
}
