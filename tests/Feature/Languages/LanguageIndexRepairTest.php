<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Runs the work outside the test's transaction, as a concurrent index build
 * must, then opens it again; the work leaves no row behind, every index
 * valid and the one-language index and key gone, as the migrations left them.
 */
function lirOutside(Closure $work): void
{
    $connection = DB::connection();
    $level = $connection->transactionLevel();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    try {
        $work();
    } finally {
        DB::table('contents')->where('slug', 'twin')->delete();
        DB::table('terms')->where('slug', 'twin')->delete();
        DB::table('users')->where('email', 'like', '%@twin.test')->delete();
        (require database_path('migrations/2027_04_01_100200_add_language_indexes.php'))->up();
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_type_slug_live_unique');
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_taxonomy_slug_unique');

        while ($connection->transactionLevel() < $level) {
            $connection->beginTransaction();
        }
    }
}

/**
 * Whether the index exists, and whether PostgreSQL may use it; null when it doesn't exist.
 */
function lirValid(string $index): ?bool
{
    $row = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$index]);

    return $row === null ? null : (bool) $row->indisvalid;
}

/**
 * Leaves the slug index as a cancelled build leaves it: present, and invalid.
 * With $keepTwin the duplicate that stopped it stays, so a build fails again.
 */
function lirPlantInvalid(bool $keepTwin = false): void
{
    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_type_slug_language_live_unique');
    $author = User::factory()->create(['email' => 'author@twin.test']);
    $first = Content::factory()->create(['slug' => 'twin', 'author_id' => $author->id]);
    Content::factory()->create(['slug' => 'twin', 'author_id' => $author->id]);

    try {
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY contents_type_slug_language_live_unique ON contents (type, slug, language) NULLS NOT DISTINCT WHERE deleted_at IS NULL');
    } catch (Throwable) {
        // The duplicate stops the build half way, as a cancel does.
    }

    if (! $keepTwin) {
        DB::table('contents')->where('slug', 'twin')->where('id', '!=', $first->id)->delete();
    }
}

/**
 * Leaves the term slug index as a cancelled build leaves it: present, and invalid.
 * With $keepTwin the duplicate that stopped it stays, so a build fails again.
 */
function lirPlantInvalidTerm(bool $keepTwin = false): void
{
    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS terms_taxonomy_slug_language_unique');
    $first = strtolower((string) Str::ulid());
    DB::table('terms')->insert([
        ['id' => $first, 'taxonomy' => 'category', 'slug' => 'twin', 'name' => 'Twin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => strtolower((string) Str::ulid()), 'taxonomy' => 'category', 'slug' => 'twin', 'name' => 'Twin', 'created_at' => now(), 'updated_at' => now()],
    ]);

    try {
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY terms_taxonomy_slug_language_unique ON terms (taxonomy, slug, language) NULLS NOT DISTINCT');
    } catch (Throwable) {
    }

    if (! $keepTwin) {
        DB::table('terms')->where('slug', 'twin')->where('id', '!=', $first)->delete();
    }
}

it('rebuilds a language index that a cancelled build left invalid', function (): void {
    lirOutside(function (): void {
        lirPlantInvalid();
        expect(lirValid('contents_type_slug_language_live_unique'))->toBeFalse();

        (require database_path('migrations/2027_04_01_100200_add_language_indexes.php'))->up();

        expect(lirValid('contents_type_slug_language_live_unique'))->toBeTrue();
    });
});

it('builds the slug index again itself when it finds it invalid, then drops the one-language slug index', function (): void {
    lirOutside(function (): void {
        lirPlantInvalid();
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS contents_type_slug_live_unique ON contents (type, slug) WHERE deleted_at IS NULL');
        expect(lirValid('contents_type_slug_language_live_unique'))->toBeFalse();

        (require database_path('migrations/2027_04_01_100300_drop_single_language_slug_index.php'))->up();

        expect(lirValid('contents_type_slug_language_live_unique'))->toBeTrue()
            ->and(lirValid('contents_type_slug_live_unique'))->toBeNull();
    });
});

it('stops before dropping the one-language slug index when the slug index still cannot be built, saying why', function (): void {
    lirOutside(function (): void {
        lirPlantInvalid(keepTwin: true);

        expect(fn () => (require database_path('migrations/2027_04_01_100300_drop_single_language_slug_index.php'))->up())
            ->toThrow(RuntimeException::class, 'contents_type_slug_language_live_unique could not be built, so the old index stays. Look for what stops it, such as two rows with the same slug in one language, then run php artisan migrate again.');
        expect(fn () => (require database_path('migrations/2027_04_01_100400_drop_single_language_term_slug_key.php'))->up())
            ->not->toThrow(RuntimeException::class);
    });
});

it('builds the term slug index again itself when it finds it invalid, then drops the one-language term key', function (): void {
    lirOutside(function (): void {
        lirPlantInvalidTerm();
        DB::statement('ALTER TABLE terms ADD CONSTRAINT terms_taxonomy_slug_unique UNIQUE (taxonomy, slug)');
        expect(lirValid('terms_taxonomy_slug_language_unique'))->toBeFalse();

        (require database_path('migrations/2027_04_01_100400_drop_single_language_term_slug_key.php'))->up();

        expect(lirValid('terms_taxonomy_slug_language_unique'))->toBeTrue()
            ->and(lirValid('terms_taxonomy_slug_unique'))->toBeNull();
    });
});

it('stops before dropping the one-language term key when the term slug index still cannot be built, saying why', function (): void {
    lirOutside(function (): void {
        lirPlantInvalidTerm(keepTwin: true);

        expect(fn () => (require database_path('migrations/2027_04_01_100400_drop_single_language_term_slug_key.php'))->up())
            ->toThrow(RuntimeException::class, 'terms_taxonomy_slug_language_unique could not be built, so the old index stays. Look for what stops it, such as two rows with the same slug in one language, then run php artisan migrate again.');
    });
});
