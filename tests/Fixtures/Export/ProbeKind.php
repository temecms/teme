<?php

declare(strict_types=1);

namespace Tests\Fixtures\Export;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportKind;
use RuntimeException;

/**
 * A kind for tests: yields the lines it was given, and with $failAfter
 * throws once it has yielded that many, as a kind whose read fails part
 * way would.
 */
final readonly class ProbeKind implements ExportKind
{
    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(private string $name = 'probes', private array $lines = [], private ?int $failAfter = null) {}

    public function name(): string
    {
        return $this->name;
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Lines a test gave.';
    }

    public function noun(): string
    {
        return 'probe|probes';
    }

    public function fields(): array
    {
        return ['n' => 'A number.'];
    }

    public function lines(ExportContext $context): iterable
    {
        foreach ($this->lines as $index => $line) {
            if ($this->failAfter !== null && $index >= $this->failAfter) {
                throw new RuntimeException('A kind failed on purpose.');
            }

            yield $line;
        }

        if ($this->failAfter !== null && $this->failAfter >= count($this->lines)) {
            throw new RuntimeException('A kind failed on purpose.');
        }
    }
}
