<?php

declare(strict_types=1);

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\Queries\AiActivityList;
use App\Domain\Ai\Tasks\Rewrite\RewritePreset;
use App\Domain\Ai\Tasks\Rewrite\RewriteTask;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\SaveDraft;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->task = new RewriteTask;

    // What a rewrite is asked about: a revision of the item, the block at $path, a selection in it, and a preset.
    $this->subject = fn (Content $item, string $path, int $from, int $to, ?string $variant = 'shorter', ?string $revisionId = null): AiSubject => new AiSubject(
        AiSubjectType::Content,
        $item->id,
        $revisionId ?? $item->refresh()->working_revision_id,
        $path,
        array_filter(['from' => $from, 'to' => $to, 'variant' => $variant], fn (mixed $value): bool => $value !== null),
    );
});

it('names the task as the pipeline and Settings › AI read it', function (): void {
    expect($this->task->key())->toBe('rewrite')
        ->and($this->task->label())->toBe('Rewrite')
        ->and($this->task->subject())->toBe(AiSubjectType::Content)
        ->and($this->task->capabilities())->toBe([AiCapability::Text])
        ->and($this->task->timeoutSeconds())->toBe(30)
        ->and($this->task->maxOutputTokens())->toBe(3_000)
        ->and($this->task->outputSchema())->toBeNull()
        ->and($this->task->appliedBy())->toBe(SaveDraft::class);
});

it('pins version 1 of its prompt to its hash, so a prompt changed without a new version stops the task (I-316)', function (): void {
    $prompt = $this->task->prompt();

    expect(RewriteTask::HASH)->toBe('a87817a2713273f44edb5d8749ee55ff29c5bd2cdd529c8ead82a0b86bc9e41d')
        ->and(strlen(RewriteTask::INSTRUCTIONS))->toBe(1_793)
        ->and(substr_count(RewriteTask::INSTRUCTIONS, "\n"))->toBe(15)
        ->and(str_contains(RewriteTask::INSTRUCTIONS, "\r"))->toBeFalse()
        ->and($prompt->key)->toBe('rewrite')
        ->and($prompt->version)->toBe(1)
        ->and($prompt->hash)->toBe(AiPrompt::hashOf(RewriteTask::INSTRUCTIONS))
        ->and($prompt->matches())->toBeTrue();
});

it('tells the model what each preset asks, by the label a request names, in the menu\'s order', function (): void {
    $lines = array_values(array_filter(explode("\n", RewriteTask::INSTRUCTIONS), fn (string $line): bool => str_starts_with($line, '- ')));

    expect(array_map(fn (string $line): string => substr($line, 2, (int) strpos($line, ':') - 2), $lines))
        ->toBe(array_map(fn (RewritePreset $preset): string => $preset->label(), RewritePreset::cases()));
});

it('keeps the seven presets in the menu\'s order, each label the words History reads from its variant (I-1309, I-1323)', function (): void {
    expect(array_map(fn (RewritePreset $preset): string => $preset->label(), RewritePreset::cases()))
        ->toBe(['Improve', 'Shorter', 'Longer', 'Simpler', 'Fix spelling and grammar', 'More formal', 'More casual']);

    foreach (RewritePreset::cases() as $preset) {
        expect(AiActivityList::words($preset->value))->toBe($preset->label());
    }
});

it('gives the model the title, the paragraph, the selection and the preset, from the revision asked about (I-316)', function (): void {
    $item = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday, the council says.');
    $input = $this->task->input(($this->subject)($item, '1', 6, 13));

    expect($input->fields)->toBe([
        'title' => 'Harbour news',
        'paragraph' => 'Fares rise on Monday, the council says.',
        'selection' => 'rise on',
        'preset' => 'Shorter',
    ])->and($input->images)->toBe([]);
});

it('reads a heading, and the paragraph inside a list item, by their paths', function (string $kind): void {
    $item = aiDraft($this->author, 'Intro.');
    $block = $kind === 'a heading'
        ? ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fares rise on Monday']]]
        : ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [docText('Fares rise on Monday')]]]];
    docSave($item, $this->author, docWith(docText('Intro.'), $block));

    $input = $this->task->input(($this->subject)($item, $kind === 'a heading' ? '1' : '1.0.0', 0, 5, 'more-formal'));

    expect($input->fields['paragraph'])->toBe('Fares rise on Monday')
        ->and($input->fields['selection'])->toBe('Fares')
        ->and($input->fields['preset'])->toBe('More formal');
})->with(['a heading', 'a list item']);

it('reads the revision asked about, not one saved since', function (): void {
    $item = aiDraft($this->author, 'The ferry is late.');
    $asked = $item->working_revision_id;
    docSave($item, $this->author, docWith(docText('The boat is early.')));

    expect($this->task->input(($this->subject)($item, '0', 4, 9, revisionId: $asked))->fields['selection'])->toBe('ferry');
});

it('measures the selection as the editor does, a character outside the basic plane counting two', function (): void {
    $item = aiDraft($this->author, mb_chr(0x1F6A2).' The ferry is late.');

    // The ship is two units in the editor's measure, so "ferry" runs from 7 to 12.
    expect($this->task->input(($this->subject)($item, '0', 7, 12))->fields['selection'])->toBe('ferry');
});

it('refuses a request the Rewrite menu never sends, before any model is asked', function (string $case, string $message): void {
    $item = aiDraft($this->author, 'The ferry is late.');
    $subject = match ($case) {
        'unknown preset' => ($this->subject)($item, '0', 4, 9, 'funnier'),
        'no preset' => ($this->subject)($item, '0', 4, 9, null),
        'no target' => new AiSubject(AiSubjectType::Content, $item->id, $item->working_revision_id, null, ['variant' => 'shorter']),
        default => ($this->subject)($item, '0', 4, 40),
    };

    expect(fn () => $this->task->input($subject))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a preset it doesn\'t have' => ['unknown preset', 'A rewrite needs one of its seven presets as its variant.'],
    'no preset' => ['no preset', 'A rewrite needs one of its seven presets as its variant.'],
    'no selection' => ['no target', 'A rewrite is asked about text selected in one paragraph or heading.'],
    'a selection past the paragraph\'s end' => ['outside', 'The selection isn\'t inside that paragraph.'],
]);

it('reads a revision of another item as gone', function (): void {
    $item = aiDraft($this->author, 'The ferry is late.');
    $other = aiDraft($this->author, 'Another story.');

    expect(fn () => $this->task->input(($this->subject)($item, '0', 4, 9, revisionId: $other->working_revision_id)))
        ->toThrow(ModelNotFoundException::class);
});

it('takes the answer as one line of text, trimmed', function (): void {
    expect($this->task->check(aiText("  The boat is late.\n")))->toBe(['text' => 'The boat is late.']);
});

it('refuses an answer it can\'t use', function (string $case): void {
    $text = match ($case) {
        'empty' => '',
        'spaces' => "  \n ",
        'two lines' => "The boat\nis late.",
        default => str_repeat('a', 10_001),
    };

    expect(fn () => $this->task->check(aiText($text)))->toThrow(AiFailure::class);
})->with(['empty', 'spaces', 'two lines', 'longer than 10,000 characters']);

it('lists in the Rewrite menu the presets the task knows, with the same variants and words, in order (I-1309)', function (): void {
    $source = (string) file_get_contents(resource_path('js/components/editor/rewrite/rewrite-presets.ts'));
    preg_match_all("/value:\s*'([a-z-]+)',\s*label:\s*'([^']+)'/", $source, $found, PREG_SET_ORDER);

    expect(array_map(fn (array $match): array => [$match[1], $match[2]], $found))
        ->toBe(array_map(fn (RewritePreset $preset): array => [$preset->value, $preset->label()], RewritePreset::cases()));
});
