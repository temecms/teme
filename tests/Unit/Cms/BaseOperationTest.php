<?php

declare(strict_types=1);

use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;

final readonly class FakeInput implements OperationInput
{
    public function __construct(public bool $authorized = true, public bool $blowUp = false) {}

    public function actorId(): ?string
    {
        return 'actor-1';
    }
}

final class FakeOperation extends BaseOperation
{
    /** @var list<string> */
    public array $calls = [];

    public bool $insideTransactionDuringHooks = true;

    protected function authorize(OperationInput $input): void
    {
        $this->calls[] = 'authorize';

        if (! $input->authorized) {
            throw new RuntimeException('forbidden');
        }
    }

    protected function validate(OperationInput $input): void
    {
        $this->calls[] = 'validate';
    }

    protected function perform(OperationInput $input): mixed
    {
        $this->calls[] = 'perform';

        if ($input->blowUp) {
            throw new RuntimeException('mutation failed');
        }

        return 'result';
    }

    protected function afterCommit(mixed $result): void
    {
        $this->calls[] = 'hooks';
        $this->insideTransactionDuringHooks = DB::transactionLevel() > 0;
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->calls[] = 'cache';
    }
}

it('runs authorize, validate, perform, hooks, cache in that order', function (): void {
    $op = new FakeOperation;

    expect($op->execute(new FakeInput))->toBe('result');
    expect($op->calls)->toBe(['authorize', 'validate', 'perform', 'hooks', 'cache']);
});

it('never reaches validate or perform when authorization fails', function (): void {
    $op = new FakeOperation;

    expect(fn () => $op->execute(new FakeInput(authorized: false)))
        ->toThrow(RuntimeException::class, 'forbidden');

    expect($op->calls)->toBe(['authorize']);
});

it('fires hooks and cache invalidation after the transaction commits', function (): void {
    $op = new FakeOperation;
    $op->execute(new FakeInput);

    expect($op->insideTransactionDuringHooks)->toBeFalse();
});

it('does not fire hooks or cache invalidation when the mutation throws', function (): void {
    $op = new FakeOperation;

    expect(fn () => $op->execute(new FakeInput(blowUp: true)))
        ->toThrow(RuntimeException::class, 'mutation failed');

    expect($op->calls)->toBe(['authorize', 'validate', 'perform']);
});
