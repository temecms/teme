<?php

declare(strict_types=1);

namespace App\Domain\Support\Security;

use InvalidArgumentException;
use LogicException;

/**
 * Who a queued job acts for (I-392): a person, by ID, or the operator. The
 * operator is made only while the operator is present at the server's
 * shell, so a job queued from a web request can never act as the operator;
 * on the worker, bind() gives the job the operator's elevation, and nothing
 * else does.
 */
final readonly class JobActor
{
    private function __construct(public ?string $userId) {}

    /**
     * A person, by their ID: digits, above zero, as `users.id` holds them.
     *
     * @throws InvalidArgumentException for anything else
     */
    public static function person(int|string $id): self
    {
        $id = (string) $id;

        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            throw new InvalidArgumentException('A job’s actor is a person’s ID, or the operator.');
        }

        return new self($id);
    }

    /**
     * The operator, made only while the operator is present.
     *
     * @throws LogicException anywhere else: a web request, a worker, a test that hasn't bound the operator
     */
    public static function operator(): self
    {
        if (! Operator::isPresent()) {
            throw new LogicException('Only the operator at the server’s shell queues work as the operator.');
        }

        return new self(null);
    }

    /**
     * The actor an Operation's input names: that person, or, for none, the
     * operator, as operator() allows.
     */
    public static function of(?string $actorId): self
    {
        return $actorId === null ? self::operator() : self::person($actorId);
    }

    public function isOperator(): bool
    {
        return $this->userId === null;
    }

    /**
     * Called first in a job's handle(): the operator's elevation for the
     * operator, nothing for a person. Elevation is bound scoped, so it ends
     * with the job (docs/architecture/operations.md).
     */
    public function bind(): void
    {
        if ($this->isOperator()) {
            app()->instance(Elevation::class, new OperatorElevation);
        }
    }
}
