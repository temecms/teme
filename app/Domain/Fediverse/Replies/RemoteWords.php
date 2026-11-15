<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies;

use App\Domain\Comments\CommentDocument;
use App\Domain\Comments\CommentText;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Phases\Comments\CommentWords;
use Illuminate\Validation\ValidationException;

/**
 * A reply's HTML as the restricted comment document (I-267, rule 7): read
 * by the same reader as an imported comment, which keeps paragraphs,
 * quotes, line breaks, bold, italic, code and links, drops scripts and
 * everything else, and links the site's own pages by their IDs (rule 9).
 * HTML is never stored.
 */
final readonly class RemoteWords
{
    /** The longest reply taken, in characters of plain text. */
    public const int MAX = 5000;

    public function __construct(private CommentWords $words) {}

    /**
     * The document, or null when the reply holds no words or too many.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $html): ?array
    {
        $document = $this->words->read($html, new ConversionSite(rtrim(url('/'), '/').'/'))['document'];
        $plain = trim(CommentText::plain($document));

        if ($plain === '' || mb_strlen($plain) > self::MAX) {
            return null;
        }

        try {
            CommentDocument::validate($document);
        } catch (ValidationException) {
            $document = CommentText::toDocument($plain);
        }

        return $document;
    }
}
