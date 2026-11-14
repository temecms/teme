<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\TranslatedContent;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Regions\Regions;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ContentSaved;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

function trTranslate(User $actor, string $sourceId, string $language): TranslatedContent
{
    return app(TranslateContent::class)->execute(new TranslateContentInput($sourceId, $language, (string) $actor->id));
}

/**
 * The working revision of an item, read again.
 */
function trWorking(string $id): ContentRevision
{
    return ContentRevision::query()->findOrFail(Content::query()->whereKey($id)->value('working_revision_id'));
}

/**
 * A published English post with this body, these fields and terms, in no group.
 *
 * @param  array<mixed>  $document
 * @param  array<string, mixed>  $fields
 * @param  list<string>  $terms
 */
function trPost(User $editor, string $title, array $document, array $fields = [], array $terms = [], bool $publish = true): Content
{
    $item = lifecycleDraft($editor, $title);
    docSave($item, $editor, $document, $fields, $terms);

    if ($publish) {
        listsPublish($item, $editor);
    }

    return $item->refresh();
}

/**
 * Every count Translate could change, and the source's group.
 *
 * @return array<string, mixed>
 */
function trState(string $sourceId): array
{
    return [
        'contents' => DB::table('contents')->count(),
        'revisions' => DB::table('content_revisions')->count(),
        'terms' => DB::table('terms')->count(),
        'group' => DB::table('contents')->where('id', $sourceId)->value('translation_group'),
    ];
}

it('makes a French draft from the published English version, in one group with it', function (): void {
    $made = trTranslate($this->site->editor, $this->site->alone, 'fr')->content;
    $group = (string) DB::table('contents')->where('id', $this->site->alone)->value('translation_group');

    expect($made->language)->toBe('fr')
        ->and($made->status)->toBe('draft')
        ->and($made->translation_group)->toBe($group)
        ->and($group)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($group)->not->toBe($made->id)
        ->and($group)->not->toBe($this->site->alone)
        ->and((int) $made->author_id)->toBe($this->site->editor->id);
});

it('copies the title, the body with its block IDs, the excerpt and the fields, and clears the canonical', function (): void {
    $document = docWith(['type' => 'paragraph', 'attrs' => ['id' => str_repeat('P', 26)], 'content' => [['type' => 'text', 'text' => 'Words']]]);
    $source = trPost($this->site->editor, 'Ferry times', $document, [BuiltInFields::EXCERPT => 'Short', BuiltInFields::CANONICAL => 'https://elsewhere.example/ferry/']);

    $copy = trWorking(trTranslate($this->site->editor, $source->id, 'fr')->content->id);

    expect($copy->title)->toBe('Ferry times')
        ->and($copy->document)->toBe(trWorking($source->id)->document)
        ->and($copy->fields[BuiltInFields::EXCERPT] ?? null)->toBe('Short')
        ->and(array_key_exists(BuiltInFields::CANONICAL, (array) $copy->fields))->toBeFalse();
});

it('points links at the French versions the group has, and keeps the rest', function (): void {
    $sequel = '01K6A0P1CE000000000000FC06';
    app(FieldRegistry::class)->register(new Field($sequel, 'sequel', 'Sequel', 'Another item.', new EntryField, ['post'], '0.2.0'));
    $link = fn (string $href, string $text): array => ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]];
    $document = docWith(
        ['type' => 'paragraph', 'content' => [$link('teme:content/'.$this->site->post, 'Hello'), $link('teme:content/'.$this->site->alone.'#more', 'Alone'), $link('teme:term/'.$this->site->news, 'News')]],
        ['type' => 'button', 'attrs' => ['id' => str_repeat('B', 26), 'label' => 'Go', 'contentId' => $this->site->post]],
    );
    $source = trPost($this->site->editor, 'Linker', $document, [$sequel => $this->site->post]);

    $copy = trWorking(trTranslate($this->site->editor, $source->id, 'fr')->content->id);
    $marks = array_map(fn (array $text): string => $text['marks'][0]['attrs']['href'], $copy->document['content'][0]['content']);

    expect($marks)->toBe(['teme:content/'.$this->site->postFr, 'teme:content/'.$this->site->alone.'#more', 'teme:term/'.$this->site->newsFr])
        ->and($copy->document['content'][1]['attrs']['contentId'])->toBe($this->site->postFr)
        ->and($copy->fields[$sequel])->toBe($this->site->postFr);
});

it('files the draft under the French versions of its terms, making the missing ones in their group', function (): void {
    $sport = typesTerm($this->site->editor, 'category', 'Sport');
    $source = trPost($this->site->editor, 'Match', docWith(docText('Match')), [], [$this->site->news, $sport->id]);

    $result = trTranslate($this->site->editor, $source->id, 'fr');
    $sportFr = Term::query()->findOrFail($result->madeTerms[0]);

    expect($result->madeTerms)->toHaveCount(1)
        ->and([$sportFr->name, $sportFr->slug, $sportFr->language, $sportFr->taxonomy])->toBe(['Sport', 'sport', 'fr', 'category'])
        ->and($sportFr->translation_group)->not->toBeNull()
        ->and($sportFr->translation_group)->toBe(Term::query()->findOrFail($sport->id)->translation_group)
        ->and(trWorking($result->content->id)->terms)->toBe([$this->site->newsFr, $sportFr->id]);
});

it('makes a missing term\'s parent first', function (): void {
    $regions = typesTerm($this->site->editor, 'category', 'Regions');
    $local = typesTerm($this->site->editor, 'category', 'Local');
    DB::table('terms')->where('id', $local->id)->update(['parent_id' => $regions->id]);
    $source = trPost($this->site->editor, 'Market', docWith(docText('Market')), [], [$local->id]);

    $made = trTranslate($this->site->editor, $source->id, 'fr')->madeTerms;
    $terms = Term::query()->whereIn('id', $made)->get()->keyBy('id');

    expect($made)->toHaveCount(2)
        ->and($terms[$made[0]]->name)->toBe('Regions')
        ->and($terms[$made[1]]->name)->toBe('Local')
        ->and($terms[$made[1]]->parent_id)->toBe($made[0])
        ->and($terms[$made[0]]->language)->toBe('fr');
});

it('maps the primary category to its French version', function (): void {
    $source = trPost($this->site->editor, 'Primary', docWith(docText('Primary')), [BuiltInFields::PRIMARY_CATEGORY => $this->site->news], [$this->site->news]);

    expect(trWorking(trTranslate($this->site->editor, $source->id, 'fr')->content->id)->fields[BuiltInFields::PRIMARY_CATEGORY])->toBe($this->site->newsFr);
});

it('records the copied revision on the draft\'s first revision', function (): void {
    $published = (string) Content::query()->whereKey($this->site->alone)->value('published_revision_id');

    expect(trWorking(trTranslate($this->site->editor, $this->site->alone, 'fr')->content->id)->source_revision_id)->toBe($published);
});

it('gives the draft the source\'s slug while it is free in that language', function (): void {
    expect(trTranslate($this->site->editor, $this->site->alone, 'fr')->content->slug)->toBe('alone');

    $other = trPost($this->site->editor, 'Alone', docWith(docText('Another')));
    expect($other->slug)->toBe('alone-2')
        ->and(trTranslate($this->site->editor, $other->id, 'fr')->content->slug)->toBe('alone-2');

    $third = trPost($this->site->editor, 'Bonjour', docWith(docText('Bonjour in English')));
    expect(trTranslate($this->site->editor, $third->id, 'fr')->content->slug)->toBe('bonjour-2');
});

it('copies an unpublished source only for someone who may edit it, and tells anyone else to wait', function (): void {
    $draft = trPost($this->site->editor, 'Unfinished', docWith(docText('Not yet')), publish: false);
    $contributor = User::factory()->create()->assignRole('contributor');
    $before = trState($draft->id);

    expect(fn () => trTranslate($contributor, $draft->id, 'fr'))->toThrow(function (AuthorizationException $refusal): void {
        expect($refusal->getMessage())->toBe('The English (United Kingdom) version isn\'t published yet. Ask someone who can edit it, or wait until it is.');
    });
    expect(trState($draft->id))->toBe($before);

    $made = trTranslate($this->site->editor, $draft->id, 'fr')->content;
    expect(trWorking($made->id)->source_revision_id)->toBe($draft->working_revision_id);
});

it('copies a private or protected source only for someone who may edit it', function (string $visibility): void {
    DB::table('contents')->where('id', $this->site->alone)->update(['visibility' => $visibility, 'reader_password' => $visibility === 'protected' ? 'hashed' : null]);
    $contributor = User::factory()->create()->assignRole('contributor');
    $before = trState($this->site->alone);

    expect(fn () => trTranslate($contributor, $this->site->alone, 'fr'))->toThrow(AuthorizationException::class);
    expect(trState($this->site->alone))->toBe($before)
        ->and(trTranslate($this->site->editor, $this->site->alone, 'fr')->content->language)->toBe('fr');
})->with(['private', 'protected']);

it('refuses a language the group already has, naming that version', function (): void {
    expect(fn () => trTranslate($this->site->editor, $this->site->post, 'fr'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['This post already has a French version: “Bonjour”.']);
    });
});

it('refuses the source\'s own language and a language the site does not have', function (): void {
    expect(fn () => trTranslate($this->site->editor, $this->site->alone, 'en-GB'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['It\'s already in English (United Kingdom).']);
    })->and(fn () => trTranslate($this->site->editor, $this->site->alone, 'de'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['German isn\'t one of your site\'s languages.']);
    });
});

it('refuses a source in the trash', function (): void {
    pageTrash(Content::query()->findOrFail($this->site->alone), $this->site->editor);
    $before = trState($this->site->alone);

    expect(fn () => trTranslate($this->site->editor, $this->site->alone, 'fr'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['source'])->toBe(['Restore it first.']);
    });
    expect(trState($this->site->alone))->toBe($before);
});

it('translates into a hidden language', function (): void {
    expect(trTranslate($this->site->editor, $this->site->alone, 'es')->content->language)->toBe('es');
});

it('translates a region into its French version under the same fixed slug', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $region = regionCreate($this->site->editor);
    regionSave($region, $this->site->editor, [docText('Join the newsletter')]);
    listsPublish($region->refresh(), $this->site->editor);

    $made = trTranslate($this->site->editor, $region->id, 'fr')->content;
    app()->forgetInstance(Regions::class);

    expect([$made->type, $made->slug, $made->language])->toBe([$region->type, $region->slug, 'fr'])
        ->and(json_encode(app(Regions::class)->published('good', 'after-content')))->toContain('Join the newsletter');
});

it('puts a page\'s translation under its parent\'s version in that language, or at the top and says so', function (): void {
    $team = LanguageSite::version($this->site->editor, 'Team', null, null, [], publish: true, type: 'page');
    DB::table('contents')->where('id', $team)->update(['parent_id' => $this->site->page]);
    $services = LanguageSite::version($this->site->editor, 'Services', null, null, [], publish: true, type: 'page');
    $repairs = LanguageSite::version($this->site->editor, 'Repairs', null, null, [], publish: true, type: 'page');
    DB::table('contents')->where('id', $repairs)->update(['parent_id' => $services]);

    $under = trTranslate($this->site->editor, $team, 'fr');
    $top = trTranslate($this->site->editor, $repairs, 'fr');

    expect([$under->content->parent_id, $under->parentLeft])->toBe([$this->site->pageFr, false])
        ->and([$top->content->parent_id, $top->parentLeft])->toBe([null, true])
        ->and($top->parentLeftMessage())->toBe('Its parent “Services” has no French version yet, so it is at the top. Choose its parent once there is one.');
});

it('announces the new draft as saved, telling its language', function (): void {
    $heard = hkHear(ContentSaved::class);

    $made = trTranslate($this->site->editor, $this->site->alone, 'fr')->content;

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->item->id)->toBe($made->id)
        ->and($heard[0]->item->language)->toBe('fr')
        ->and($heard[0]->created)->toBeTrue();
});

it('purges no public page', function (): void {
    $folder = cacheOn();

    try {
        cacheShown(['/alone/' => ['content:'.$this->site->alone], '/' => ['list:post']]);
        trTranslate($this->site->editor, $this->site->alone, 'fr');

        expect(cacheKept('/alone/', '/'))->toBe(['/alone/', '/']);
    } finally {
        cacheOff($folder);
    }
});

it('writes nothing when the person may not create items of the type', function (): void {
    $nobody = User::factory()->create();
    $before = trState($this->site->alone);

    expect(fn () => trTranslate($nobody, $this->site->alone, 'fr'))->toThrow(AuthorizationException::class);
    expect(trState($this->site->alone))->toBe($before);
});

it('refuses a second French version found under the lock', function (string $when): void {
    $group = strtolower((string) Str::ulid());
    DB::table('contents')->where('id', $this->site->alone)->update(['translation_group' => $group]);
    $planted = false;

    DB::connection()->beforeExecuting(function (string $query) use ($when, $group, &$planted): void {
        // Under the lock before the group is read, or after it is checked and before the draft is written.
        $at = $when === 'lock' ? str_contains($query, "hashtext('teme.languages')") : str_contains($query, '"slug"');

        if ($planted || ! $at) {
            return;
        }

        $planted = true;
        $row = (array) DB::table('contents')->where('id', $this->site->alone)->first();
        DB::table('contents')->insert([...$row, 'id' => strtolower((string) Str::ulid()), 'language' => 'fr', 'slug' => 'seul', 'translation_group' => $group, 'working_revision_id' => null, 'published_revision_id' => null, 'status' => 'draft', 'published_at' => null]);
    });

    expect(fn () => trTranslate($this->site->editor, $this->site->alone, 'fr'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['This post already has a French version: “Alone”.']);
    });
})->with(['lock', 'write']);
