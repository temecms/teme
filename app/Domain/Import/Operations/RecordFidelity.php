<?php

/**
 * Records one imported post's comparison with WordPress's rendered page on
 * its map row (I-337): the revision compared, the share of words lost, and
 * its one message, or none. Only an import's actor may call it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Concerns\NotesObjects;
use App\Domain\Import\Operations\Input\RecordFidelityInput;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

final class RecordFidelity extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;
    use NotesObjects;

    /** The codes this Operation keeps on a row: the comparison's own. */
    private const string PREFIX = 'fidelity.';

    /** The map's object type for a WordPress post or page (I-332). */
    private const string OBJECT = 'post';

    public function __construct(private readonly ImportMap $map) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordFidelityInput $input */
        if (! Str::isUlid($input->runId) || preg_match('/^[1-9]\d{0,17}$/', $input->objectId) !== 1 || ! Str::isUlid($input->checked)) {
            throw new InvalidArgumentException('Recording a comparison needs the run, a post’s WordPress ID and the revision compared.');
        }

        if ($input->message !== null && ! str_starts_with($input->message->code, self::PREFIX)) {
            throw new InvalidArgumentException('The comparison records only its own message.');
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var RecordFidelityInput $input */
        $run = $this->running($input->runId);

        if ($run === null) {
            return false;
        }

        $ref = SourceRef::of(self::OBJECT, $input->objectId);
        $this->lockObject($run->source_id, $ref);
        $entry = $this->map->entry($run->source_id, $ref);

        if ($entry === null) {
            return false;
        }

        $entry->forceFill(['facts' => [...$entry->facts, 'checked' => $input->checked, 'lost' => $input->lost]])->save();
        $this->noteMessages($entry, $run->id, self::PREFIX, $input->message === null ? [] : [$input->message]);

        return true;
    }
}
