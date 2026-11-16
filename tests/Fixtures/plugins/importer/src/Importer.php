<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Importer;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Teme\Contracts\Import\ImportOutcome;
use Teme\Contracts\Import\ImportScope;
use Teme\Contracts\Import\WordPressImporter;
use Teme\Contracts\Import\WordPressRecord;

/**
 * The fixture's importer: keeps each thing it is handed in its own table,
 * one row per record, and fails, refuses or breaks when a record's title
 * says so.
 */
final class Importer implements WordPressImporter
{
    public function options(array $options, ImportScope $scope): void
    {
        $this->keep('options', 0, null, ['options' => $options, 'catchUp' => $scope->isCatchUp()]);
    }

    public function record(WordPressRecord $record, ImportScope $scope): ImportOutcome
    {
        $title = $record->fields['title'] ?? null;

        if ($title === 'fail me') {
            return ImportOutcome::failed('This actor has no inbox, so it wasn’t brought across.');
        }

        if ($title === 'skip me') {
            return ImportOutcome::skipped('This actor is a test account.');
        }

        if ($title === 'break me') {
            throw new RuntimeException('The fixture broke on purpose.');
        }

        $parent = is_int($record->fields['parent'] ?? null) ? $scope->itemFor($record->fields['parent']) : null;
        $author = is_int($record->fields['author'] ?? null) ? $scope->personFor($record->fields['author']) : null;

        $this->keep($record->kind, $record->sourceId, $record->type, [
            'fields' => $record->fields,
            'meta' => $record->meta,
            'parentItem' => $parent?->id,
            'parentTitle' => $parent?->title,
            'authorPerson' => $author,
            'catchUp' => $scope->isCatchUp(),
        ]);

        return ImportOutcome::imported();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function keep(string $kind, int $sourceId, ?string $type, array $payload): void
    {
        DB::table('importer_received')->upsert(
            [['kind' => $kind, 'source_id' => $sourceId, 'type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]],
            ['kind', 'source_id'],
            ['type', 'payload'],
        );
    }
}
