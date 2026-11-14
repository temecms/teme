<?php

declare(strict_types=1);

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\BlockMappings;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;
use App\Domain\Import\Phases\ImportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The mapping registry plugins extend (I-349), and the rules for a block no
 * row maps.
 */

beforeEach(function (): void {
    $this->row = fn (array $names, array $nodes = []): BlockMapping => new class($names, $nodes) implements BlockMapping
    {
        public function __construct(private array $names, private array $nodes) {}

        public function blocks(): array
        {
            return $this->names;
        }

        public function convert(ParsedBlock $block, BlockContext $context): array
        {
            return $this->nodes;
        }
    };
    $this->tag = function (BlockMapping ...$rows): void {
        $names = [];

        foreach ($rows as $index => $row) {
            $this->app->instance("test.block-row.{$index}", $row);
            $names[] = "test.block-row.{$index}";
        }

        $this->app->tag($names, BlockMappings::TAG);
    };
    $this->said = fn (array $messages): array => array_map(fn (ImportMessage $message): array => [$message->code, $message->message, $message->context], $messages);
});

it('finds a row by its exact name, then by its namespace', function (): void {
    [$exact, $every] = [($this->row)(['acme/box']), ($this->row)(['acme/*'])];
    ($this->tag)($exact, $every);

    $mappings = app(BlockMappings::class);

    expect($mappings->find('acme/box'))->toBe($exact)
        ->and($mappings->find('Acme/Other'))->toBe($every)
        ->and($mappings->find('nobody/thing'))->toBeNull();
});

it('refuses two rows for one name', function (): void {
    ($this->tag)(($this->row)(['acme/box']), ($this->row)(['acme/box']));

    expect(fn () => app(BlockMappings::class)->find('acme/box'))->toThrow(LogicException::class, 'Two block mappings convert [acme/box]');
});

it('refuses a row whose name isn\'t namespace/name in lower case', function (): void {
    ($this->tag)(($this->row)(['Acme/Box']));

    expect(fn () => app(BlockMappings::class)->find('acme/box'))->toThrow(LogicException::class, 'A block mapping’s name [Acme/Box] must be namespace/name or namespace/*, in lower case.');
});

it('lets a plugin\'s row convert its block', function (): void {
    ($this->tag)(($this->row)(['acme/box'], [Nodes::rule()]));

    $conversion = wpConvert([wpBlock('acme/box', '<div class="box">x</div>')]);

    expect($conversion->document['content'])->toBe([['type' => 'horizontalRule']])
        ->and($conversion->messages)->toBe([]);
});

it('keeps what a block no row maps holds when it holds only other blocks, and says so', function (): void {
    $conversion = wpConvert([
        wpBlock('acme/row', '<div class="acme-row">%s</div>', [], [[...wpBlock(null, 'Inside'), 'autop' => '<p>Inside</p>']]),
        // A layout's own style element is no content of its own.
        wpBlock('acme/styled', '<div class="acme-styled"><style>.acme-styled{gap:2em}</style>%s</div>', [], [[...wpBlock(null, 'Styled'), 'autop' => '<p>Styled</p>']]),
    ]);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Inside']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Styled']]],
    ]))->and(($this->said)($conversion->messages))->toBe([
        ['block.flattened', 'Kept what 1 acme/row block held, in order, without its layout.', ['name' => 'acme/row', 'count' => 1]],
        ['block.flattened', 'Kept what 1 acme/styled block held, in order, without its layout.', ['name' => 'acme/styled', 'count' => 1]],
    ]);
});

it('keeps a block no row maps that has content of its own as imported content, and says so', function (): void {
    $notice = wpBlock('acme/notice', '<div class="notice"><p>Careful</p></div>');

    expect(($this->said)(wpConvert([$notice, $notice])->messages))->toBe([
        ['block.imported', 'Kept 2 acme/notice blocks as imported content: Teme can’t turn them into its own blocks.', ['name' => 'acme/notice', 'count' => 2]],
    ]);
});

it('drops a block no row maps that saved nothing, as dynamic, and says so', function (): void {
    expect(($this->said)(wpConvert([wpBlock('acme/latest', "\n")])->messages))->toBe([
        ['block.dynamic', 'Left out 1 acme/latest block: WordPress drew it when the page loaded, and on Teme the theme does that job.', ['name' => 'acme/latest', 'count' => 1]],
    ]);
});

it('gives an empty paragraph for an article whose every block is left out', function (): void {
    $document = wpConvert([wpBlock('acme/latest', ''), wpBlock(null, "\n\n")])->document;

    expect(wpWithoutIds($document))->toBe(wpWithoutIds(['type' => 'doc', 'content' => [['type' => 'paragraph']]]))
        ->and(wpEditorCheck(['empty' => $document]))->toBe(['empty' => null]);

    wpAccepted($document);
});
