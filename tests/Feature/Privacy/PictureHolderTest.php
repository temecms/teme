<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\People\Privacy\PeopleHolder;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Privacy\PrivacyRequestsHolder;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Domain\Users\Operations\SetPicture;
use App\Domain\Users\Privacy\PictureHolder;
use App\Domain\Users\Queries\PersonPictures;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Privacy\PersonalDataSubject;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])->assignRole('editor');
    $this->subject = new PersonalDataSubject('ada@example.com', (string) $this->ada->id);

    $this->give = function (User $user): string {
        $image = mediaUpload($user, mediaJpeg(400, 400), 'Portrait.jpg', allowDuplicate: true);
        app(SetPicture::class)->execute(new SetPictureInput((string) $user->id, $image->id, (string) $user->id));

        return $image->id;
    };

    // Erases through the Operation with this one holder, as a request runs it, and returns its entry.
    $this->erase = function (string $email = 'ada@example.com'): array {
        app()->instance(Elevation::class, new OperatorElevation);
        app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), [PictureHolder::class]));
        $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', $email, 'Asked by email.', false));

        while (! app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id))->done);

        return PrivacyRequest::query()->findOrFail($id)->results[0];
    };
});

it('exports the picture’s address and file name', function (): void {
    ($this->give)($this->ada);
    $page = app(PictureHolder::class)->export($this->subject, 1);

    expect($page->done)->toBeTrue()
        ->and($page->groups)->toHaveCount(1)
        ->and($page->groups[0]->title)->toBe('Your picture')
        ->and($page->groups[0]->values)->toBe([
            'Picture' => app(PersonPictures::class)->of((int) $this->ada->id),
            'File name' => 'Portrait.jpg',
        ]);
});

it('exports nothing for an account without a picture, or an address without an account', function (): void {
    expect(app(PictureHolder::class)->export($this->subject, 1)->groups)->toBe([])
        ->and(app(PictureHolder::class)->export(new PersonalDataSubject('nobody@example.com'), 1)->groups)->toBe([]);
});

it('clears the picture on erasure, says the image stays in Media, and leaves another account’s picture', function (): void {
    $imageId = ($this->give)($this->ada);
    $grace = User::factory()->create(['email' => 'grace@example.com'])->assignRole('editor');
    $gracesImage = ($this->give)($grace);

    expect(($this->erase)())->toMatchArray([
        'removed' => 1, 'retained' => 0, 'reasons' => ['The picture is no longer shown. The image itself stays in Media, where the site’s administrators can delete it.'],
    ])
        ->and($this->ada->refresh()->picture_media_id)->toBeNull()
        ->and($grace->refresh()->picture_media_id)->toBe($gracesImage)
        ->and(DB::table('media')->where('id', $imageId)->exists())->toBeTrue()
        ->and(($this->erase)())->toMatchArray(['removed' => 0, 'retained' => 0, 'reasons' => []]);
});

it('is listed after Bylines and before Privacy requests', function (): void {
    $core = PersonalDataHolders::CORE;
    $at = array_search(PictureHolder::class, $core, true);

    expect($at)->not->toBeFalse()
        ->and($core[$at - 1])->toBe(PeopleHolder::class)
        ->and($core[$at + 1])->toBe(PrivacyRequestsHolder::class)
        ->and(end($core))->toBe(PrivacyRequestsHolder::class)
        ->and(app(PictureHolder::class)->key())->toBe('picture')
        ->and(app(PictureHolder::class)->label())->toBe('Picture');
});
