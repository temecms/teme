<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\PreparesObjects;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A list phase over the companion's users, for the engine's own tests: it
 * prepares each person, writes them to fixture_people, skips subscribers,
 * warns of a custom role, and fails, stops or breaks on the person a test's
 * choice names.
 */
class PeoplePhase implements ListPhase, PreparesObjects
{
    public function key(): string
    {
        return 'fixture-people';
    }

    public function order(): int
    {
        return 205;
    }

    public function label(): string
    {
        return 'People';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function lists(ImportContext $context): array
    {
        // Two to a page, so five people take three pages; a count of posts changes nothing.
        return [new ImportList('users', [], 2, ['published_posts'])];
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return SourceRef::of('user', $item['id']);
    }

    public function prepare(SourceRef $ref, array $item, ImportContext $context): PreparedObject
    {
        return new PreparedNote($ref->id);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        $login = (string) $item['login'];

        if (in_array('subscriber', (array) $item['roles'], true)) {
            return ImportResult::skipped(new ImportMessage('user.subscriber', 'A subscriber, who can’t have an account on Teme yet.'));
        }

        if ($context->choice('fail') === $login) {
            throw new ImportFailed(new ImportMessage('fixture.refused', "The fixture refused {$login}."));
        }

        if ($context->choice('stop') === $login) {
            throw ImportStopped::because('fixture-stop', "Stopped by the fixture at {$login}.");
        }

        $existing = app(ImportMap::class)->temeId($context->sourceId, $ref);
        DB::table('fixture_people')->upsert([['wordpress_id' => (int) $ref->id, 'name' => (string) $item['display_name']]], ['wordpress_id'], ['name']);

        if ($context->choice('explode') === $login) {
            throw new RuntimeException("The fixture broke on {$login}.");
        }

        $result = $existing === null ? ImportResult::created('fixture-person', $ref->id) : ImportResult::updated('fixture-person', $ref->id);

        return in_array('wpseo_manager', (array) $item['roles'], true)
            ? $result->withWarning(new ImportMessage('user.custom-role', 'Their role, SEO Manager, isn’t one of Teme’s.', ['role' => 'wpseo_manager']))
            : $result;
    }
}
