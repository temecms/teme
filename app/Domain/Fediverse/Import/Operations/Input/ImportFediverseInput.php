<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportFediverse needs: the source and run, which part of the
 * WordPress plugin's data it writes (its accounts and settings, a page of
 * followers, the followers WordPress no longer has, a batch of post or
 * reply addresses), that part's data as the steps read it, and who is asking.
 */
final readonly class ImportFediverseInput implements OperationInput
{
    public const string ACTORS = 'actors';

    public const string FOLLOWERS = 'followers';

    public const string SWEEP = 'sweep';

    public const string OBJECTS = 'objects';

    public const string REPLIES = 'replies';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $sourceId,
        public string $runId,
        public string $part,
        public array $data,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
