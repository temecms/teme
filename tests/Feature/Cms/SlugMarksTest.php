<?php

declare(strict_types=1);

use App\Domain\Contents\Slugs;
use App\Domain\Import\Phases\Terms\WordPressSlugs;
use App\Domain\People\PersonSlugs;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Users\UserSlugs;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

dataset('scripts with vowel signs', [
    'Hindi' => ['द वेर्बेलिया के बारे में', 'द-वेर्बेलिया-के-बारे-में'],
    'Bengali' => ['আমাদের সম্পর্কে', 'আমাদের-সম্পর্কে'],
    'Thai' => ['เกี่ยวกับ เรา', 'เกี่ยวกับ-เรา'],
]);

it('keeps the vowel signs with their letters in an address made from a title', function (string $title, string $slug): void {
    expect(Slugs::fromTitle($title))->toBe($slug);
})->with('scripts with vowel signs');

it('keeps the vowel signs in an author’s and a person’s address', function (string $name, string $slug): void {
    expect(UserSlugs::unique($name))->toBe($slug)
        ->and(PersonSlugs::unique($name))->toBe($slug);
})->with('scripts with vowel signs');

it('keeps the vowel signs in a term’s address, made or given', function (string $name, string $slug): void {
    $this->seed(RoleSeeder::class);
    $editor = User::factory()->create()->assignRole('editor');

    $made = app(CreateTerm::class)->execute(new SaveTermInput('category', $name, null, null, null, (string) $editor->id));
    $given = app(CreateTerm::class)->execute(new SaveTermInput('tag', $name, $slug, null, null, (string) $editor->id));

    expect($made->slug)->toBe($slug)
        ->and($given->slug)->toBe($slug);
})->with('scripts with vowel signs');

it('keeps WordPress’s own address when it holds vowel signs', function (string $name, string $slug): void {
    expect(WordPressSlugs::fromWordPress(rawurlencode($slug)))->toBe(['slug' => $slug, 'kept' => true]);
})->with('scripts with vowel signs');

it('still turns a mark with no letter before it into nothing', function (): void {
    expect(Slugs::fromTitle("\u{0947}Owls"))->toBe('owls')
        ->and(WordPressSlugs::fromWordPress(rawurlencode("\u{0947}owls")))->toBe(['slug' => 'owls', 'kept' => false]);
});

dataset('decomposed titles', [
    // ো typed as its two halves, U+09C7 U+09BE; NFC holds it as U+09CB.
    'Bengali' => ["আমাদ\u{09C7}\u{09BE}র কথা"],
    // ทุ่ง with the tone mark before the vowel sign below; NFC puts the vowel sign first.
    'Thai' => ["ท\u{0E48}\u{0E38}ง นา"],
]);

it('makes an address in the form a request is looked up by, which the page answers at and the composed spelling clashes with', function (string $title): void {
    $this->seed(RoleSeeder::class);
    $editor = User::factory()->create()->assignRole('editor');
    $post = lifecycleDraft($editor, $title);
    listsPublish($post, $editor);
    $slug = $post->refresh()->slug;
    $composed = (string) Normalizer::normalize(Slugs::fromTitle($title), Normalizer::FORM_C);

    expect($slug)->toBe($composed)
        ->and(Slugs::unique('post', $composed))->toBe($composed.'-2');

    $path = (string) addrCurrent('content', $post->id);
    addrGet('/'.implode('/', array_map(rawurlencode(...), explode('/', $path))).'/')->assertOk();
})->with('decomposed titles');

it('stores a typed address in its composed form, so it can be opened', function (): void {
    $this->seed(RoleSeeder::class);
    $editor = User::factory()->create()->assignRole('editor');

    $term = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Café', "cafe\u{0301}", null, null, (string) $editor->id));

    expect($term->slug)->toBe("caf\u{00E9}")
        ->and(WordPressSlugs::fromWordPress(rawurlencode("cafe\u{0301}")))->toBe(['slug' => "caf\u{00E9}", 'kept' => true]);
});

it('refuses an address holding a variation selector, which can’t be seen', function (): void {
    $this->seed(RoleSeeder::class);
    $editor = User::factory()->create()->assignRole('editor');
    app(CreateTerm::class)->execute(new SaveTermInput('category', 'About', 'about', null, null, (string) $editor->id));

    expect(fn () => app(CreateTerm::class)->execute(new SaveTermInput('category', 'About', "abo\u{FE0F}ut", null, null, (string) $editor->id)))->toThrow(ValidationException::class)
        ->and(Slugs::fromTitle("abo\u{FE0F}ut"))->toBe('about')
        ->and(WordPressSlugs::fromWordPress(rawurlencode("abo\u{FE0F}ut"))['slug'])->toBe('about');
});
