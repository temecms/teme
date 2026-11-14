<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * Whether an AI provider's key is set, and where from (I-1308): the words
 * Settings › AI shows beside each provider. Never the key.
 */
enum AiKeyState: string
{
    case Set = 'set';
    case SetByServer = 'set-by-server';
    case NotSet = 'not-set';

    public function label(): string
    {
        return match ($this) {
            self::Set => 'Set',
            self::SetByServer => 'Set by the server',
            self::NotSet => 'Not set',
        };
    }
}
