<?php

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\Rewrite;

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiInput;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\Asking\SuggestionCheck;
use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\SaveDraft;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * The rewrite task (I-1309): the text selected inside one paragraph or
 * heading, rewritten as one of seven presets asks, with its paragraph and
 * the article's title to read it by. Used through ApplyAiSuggestion, which
 * writes it as the assistant's revision.
 */
final readonly class RewriteTask implements AiTask
{
    /** Version 1 of the prompt (I-316): every preset is in it, and a request names one. */
    public const string INSTRUCTIONS = <<<'PROMPT'
        You rewrite one passage of an article for the person writing it: the text they selected inside one paragraph, heading or list item. You are given the article's title, the whole paragraph the selection sits in, the selection itself, the preset they chose, and the site's language.

        Rewrite only the selection, as the chosen preset says:
        - Improve: make it clearer and smoother to read, keeping its meaning, its facts and about its length.
        - Shorter: say the same in fewer words, keeping every fact that matters.
        - Longer: develop it with a little more explanation or detail, drawn only from the paragraph and the title. Never add facts, names, numbers, dates or quotations of your own.
        - Simpler: use plainer words and shorter sentences, so that more readers can follow it easily.
        - Fix spelling and grammar: correct the spelling, grammar and punctuation, and change nothing else. If nothing needs correcting, answer with the selection exactly as it is.
        - More formal: make the tone more formal, keeping the meaning.
        - More casual: make the tone more relaxed and conversational, keeping the meaning.

        Write in the language of the selection, never in another. The language given is the site's own, for when the selection's language is unclear.
        Keep the meaning, the facts, the names, numbers and dates, and the point of view. Read the paragraph and the title only to understand the selection; never rewrite or repeat them.
        The selection may be part of a sentence. Make the rewrite fit where the selection stands: begin it with a capital letter only if the selection begins with one, and end it with punctuation only if the selection ends with some.

        Answer with the rewritten selection alone, on one line: no quotation marks around it, no label, no notes or explanation, and no Markdown or HTML.
        PROMPT;

    /** AiPrompt::hashOf(self::INSTRUCTIONS), pasted: a changed prompt is a new version and a new hash (I-316). */
    public const string HASH = 'a87817a2713273f44edb5d8749ee55ff29c5bd2cdd529c8ead82a0b86bc9e41d';

    public const int VERSION = 1;

    public function key(): string
    {
        return 'rewrite';
    }

    public function label(): string
    {
        return 'Rewrite';
    }

    public function subject(): AiSubjectType
    {
        return AiSubjectType::Content;
    }

    public function capabilities(): array
    {
        return [AiCapability::Text];
    }

    public function prompt(): AiPrompt
    {
        return new AiPrompt($this->key(), self::VERSION, self::INSTRUCTIONS, self::HASH);
    }

    public function timeoutSeconds(): int
    {
        return 30;
    }

    /**
     * Above the longest answer Teme takes, so a long one is refused by its
     * length, never cut short.
     */
    public function maxOutputTokens(): int
    {
        return 3_000;
    }

    public function outputSchema(): ?array
    {
        return null;
    }

    /**
     * The article's title, the paragraph or heading the selection sits in,
     * the selection and the preset, read from the revision asked about and
     * nothing else (I-316). The pipeline adds the language after them.
     *
     * @throws ModelNotFoundException when the revision or its block is gone
     * @throws InvalidArgumentException for a request the Rewrite menu never sends
     */
    public function input(AiSubject $subject): AiInput
    {
        $preset = RewritePreset::tryFrom((string) ($subject->options['variant'] ?? ''))
            ?? throw new InvalidArgumentException('A rewrite needs one of its seven presets as its variant.');

        if ($subject->target === null) {
            throw new InvalidArgumentException('A rewrite is asked about text selected in one paragraph or heading.');
        }

        $revision = ContentRevision::query()->where('content_id', $subject->id)->findOrFail($subject->revisionId);
        $block = TextBlocks::at((array) $revision->document, $subject->target)
            ?? throw (new ModelNotFoundException)->setModel(ContentRevision::class);
        $paragraph = TextBlocks::text($block);
        $from = $subject->options['from'] ?? null;
        $to = $subject->options['to'] ?? null;
        $selection = is_int($from) && is_int($to) && $from < $to ? TextBlocks::slice($paragraph, $from, $to) : null;

        if ($selection === null) {
            throw new InvalidArgumentException('The selection isn\'t inside that paragraph.');
        }

        return new AiInput([
            'title' => (string) $revision->title,
            'paragraph' => $paragraph,
            'selection' => $selection,
            'preset' => $preset->label(),
        ]);
    }

    /**
     * The rewritten selection, trimmed: one line of text, neither empty nor
     * longer than Teme takes. Teme's own check runs after it (I-316).
     *
     * @return array<string, string>
     *
     * @throws AiFailure an invalid output, when the answer isn't that
     */
    public function check(AiResult $result): array
    {
        $text = trim($result->text ?? '');

        if ($text === '' || mb_strlen($text) > SuggestionCheck::MAX_LENGTH || preg_match('/[\r\n]/', $text) === 1) {
            throw AiFailure::invalidOutput();
        }

        return ['text' => $text];
    }

    /**
     * The Operation that finally writes a used rewrite: ApplyAiSuggestion
     * checks it and saves it through this one.
     */
    public function appliedBy(): string
    {
        return SaveDraft::class;
    }
}
