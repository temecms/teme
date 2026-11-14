<?php

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\Rewrite;

/**
 * The seven ways a rewrite may change the selected text (I-1309), in the
 * order the Rewrite menu shows them. The value is the request's variant,
 * which the record keeps and History reads back as the label.
 */
enum RewritePreset: string
{
    case Improve = 'improve';
    case Shorter = 'shorter';
    case Longer = 'longer';
    case Simpler = 'simpler';
    case FixSpellingAndGrammar = 'fix-spelling-and-grammar';
    case MoreFormal = 'more-formal';
    case MoreCasual = 'more-casual';

    /**
     * Its name, as the menu shows it and the prompt names it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Improve => 'Improve',
            self::Shorter => 'Shorter',
            self::Longer => 'Longer',
            self::Simpler => 'Simpler',
            self::FixSpellingAndGrammar => 'Fix spelling and grammar',
            self::MoreFormal => 'More formal',
            self::MoreCasual => 'More casual',
        };
    }
}
