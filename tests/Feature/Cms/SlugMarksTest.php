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
