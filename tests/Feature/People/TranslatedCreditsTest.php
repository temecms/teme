<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    $this->writer = $this->site->editor;
    $this->translator = $this->site->admin;
    $this->reuters = Credited::guest('Reuters', 'reuters');
    $this->translate = fn (string $sourceId, string $language = 'fr'): string => (string) app(TranslateContent::class)
        ->execute(new TranslateContentInput($sourceId, $language, (string) $this->translator->id))->content->id;
    $this->credits = fn (string $id): array => app(Credits::class)->forItems([$id => (int) DB::table('contents')->where('id', $id)->value('author_id')])[$id];
});

it('credits the source\'s writer on a translation, owned by the translator', function (): void {
    $inferred = ($this->translate)($this->site->alone);
    $explicit = LanguageSite::version($this->writer, 'Wire story', null, null, [], publish: true);
    Credited::set($explicit, [Credit::person($this->reuters), Credit::account((int) $this->writer->id)]);
    $copied = ($this->translate)($explicit);

    expect((int) DB::table('contents')->where('id', $inferred)->value('author_id'))->toBe((int) $this->translator->id)
        ->and(($this->credits)($inferred))->toEqual([Credit::account((int) $this->writer->id)])
        ->and((int) DB::table('contents')->where('id', $copied)->value('author_id'))->toBe((int) $this->translator->id)
        ->and(($this->credits)($copied))->toEqual([Credit::person($this->reuters), Credit::account((int) $this->writer->id)])
        ->and(DB::table('content_bylines')->where('content_id', $copied)->orderBy('position')->pluck('person_id')->all())
        ->toBe(DB::table('content_bylines')->where('content_id', $explicit)->orderBy('position')->pluck('person_id')->all());
});

it('takes the languages lock, then the tree lock, then rows, with people last', function (): void {
    $page = LanguageSite::version($this->writer, 'Visiting', null, null, [], publish: true, type: 'page');
    $statements = array_column(planStatements(fn () => ($this->translate)($page)), 'query');
    $find = fn (Closure $match): int|false => collect($statements)->search($match);
    $languages = $find(fn (string $sql): bool => str_contains($sql, "hashtext('teme.languages')"));
    $tree = $find(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock') && ! str_contains($sql, 'teme.languages'));
    $accounts = $find(fn (string $sql): bool => str_contains($sql, 'FROM users') && str_contains($sql, 'FOR KEY SHARE'));
    $rows = $find(fn (string $sql): bool => str_contains($sql, 'from "contents"') && str_contains($sql, 'for update'));
    $people = $find(fn (string $sql): bool => str_contains($sql, 'INSERT INTO people'));
    $bylines = $find(fn (string $sql): bool => str_contains($sql, 'insert into "content_bylines"') || str_contains($sql, 'INSERT INTO content_bylines'));

    expect([$languages, $tree, $accounts, $rows, $people, $bylines])->each->toBeInt()
        ->and($languages)->toBeLessThan($tree)
        ->and($tree)->toBeLessThan($accounts)
        ->and($accounts)->toBeLessThan($rows)
        ->and($rows)->toBeLessThan($people)
        ->and($people)->toBeLessThan($bylines);
});

it('makes the source owner\'s person when the translation copies an inferred credit', function (): void {
    expect(DB::table('people')->where('user_id', $this->writer->id)->count())->toBe(0);

    $translation = ($this->translate)($this->site->alone);
    $person = (string) DB::table('people')->where('user_id', $this->writer->id)->value('id');

    expect($person)->not->toBe('')
        ->and(DB::table('content_bylines')->where('content_id', $translation)->get(['person_id', 'position'])->all())->toEqual([(object) ['person_id' => $person, 'position' => 0]])
        ->and(DB::table('content_bylines')->where('content_id', $this->site->alone)->count())->toBe(0);
});

it('refuses, writing nothing, when the source\'s owner changes between its read and its lock', function (): void {
    $other = User::factory()->create(['name' => 'Olu Other'])->assignRole('editor');
    $contents = DB::table('contents')->count();
    $changed = false;

    // Another request gives the source to someone else right after the owner's key share.
    DB::listen(function (QueryExecuted $query) use (&$changed, $other): void {
        if (! $changed && str_contains($query->sql, 'FOR KEY SHARE')) {
            $changed = true;
            DB::table('contents')->where('id', $this->site->alone)->update(['author_id' => $other->id]);
        }
    });

    $refused = null;

    try {
        ($this->translate)($this->site->alone);
    } catch (ValidationException $exception) {
        $refused = $exception->errors()['source'][0] ?? null;
    }

    expect($changed)->toBeTrue()
        ->and($refused)->toBe('The item changed while it was being translated. Try again.')
        ->and(DB::table('contents')->count())->toBe($contents)
        ->and(DB::table('people')->whereNotNull('user_id')->count())->toBe(0)
        ->and(DB::table('content_bylines')->count())->toBe(0);
});
