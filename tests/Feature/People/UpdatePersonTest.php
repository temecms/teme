<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\Paths;
use App\Domain\People\Credit;
use App\Domain\People\Operations\Input\UpdatePersonInput;
use App\Domain\People\Operations\UpdatePerson;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
    $this->update = fn (array $changes, ?User $actor = null, ?string $id = null): mixed => app(UpdatePerson::class)->execute(new UpdatePersonInput((string) ($actor ?? $this->admin)->id, $id ?? $this->reuters, ...$changes));
});

it('renames a guest, keeping the old address as a redirect', function (): void {
    ($this->update)(['name' => 'Reuters News', 'slug' => 'reuters-news', 'bio' => '']);

    expect((array) DB::table('people')->where('id', $this->reuters)->first(['name', 'slug', 'bio']))->toBe(['name' => 'Reuters News', 'slug' => 'reuters-news', 'bio' => null])
        ->and(Paths::current('person', $this->reuters))->toBe('author/reuters-news')
        ->and(addrLocation(addrGet('/author/reuters/')->assertStatus(301)))->toBe('/author/reuters-news/');
});

it('moves the items whose address holds {author} after a new slug, in the background', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{author}/{slug}/']]);
    Queue::fake();

    ($this->update)(['name' => 'Reuters News']);

    Queue::assertNotPushed(RecomputeAddresses::class);

    ($this->update)(['slug' => 'reuters-news']);

    Queue::assertPushed(RecomputeAddresses::class, fn (RecomputeAddresses $job): bool => $job->actor->userId === (string) $this->admin->id);
});

it('refuses an account\'s person', function (): void {
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    $article = lifecycleDraft($this->admin, 'Article');
    Credited::set($article->id, [Credit::account((int) $ada->id)]);
    $person = (string) DB::table('people')->where('user_id', $ada->id)->value('id');

    expect(fn () => ($this->update)(['name' => 'Someone else'], id: $person))->toThrow(ValidationException::class, "Their name, address and biography come from their account's profile.");
});

it('writes nothing when refused', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = [DB::table('people')->get()->all(), DB::table('paths')->get()->all()];

    expect(fn () => ($this->update)(['name' => 'Reuters News', 'slug' => 'reuters-news'], $editor))->toThrow(AuthorizationException::class);

    expect([DB::table('people')->get()->all(), DB::table('paths')->get()->all()])->toEqual($before);
});
