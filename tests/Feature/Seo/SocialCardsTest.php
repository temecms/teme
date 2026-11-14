<?php

declare(strict_types=1);

use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Queries\ShareImageUse;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Seo\SocialCards;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->image = fn (string $uri): string => headValue(headDom($uri), '//head/meta[@property="og:image"]/@content');
    // The file a card shows: the 1200×630 card once made, else the widest size (I-855).
    $this->served = function (Media $media): string {
        $variants = $media->refresh()->variants;
        $widths = array_filter($variants, fn (string $key): bool => preg_match('/^w\d+$/', $key) === 1, ARRAY_FILTER_USE_KEY);
        uasort($widths, fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return ($variants['social'] ?? reset($widths))['path'];
    };
    $this->folder = null;
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('registers Social cards at 400, its default social image a media setting with nothing chosen (I-293)', function (): void {
    $sections = array_map(fn (SearchAndSocialSection $section): array => [$section->order(), $section->key(), $section->label()], SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)));
    $setting = app(SettingsRegistry::class)->find('seo')?->setting(SocialCards::DEFAULT_IMAGE);

    expect($sections)->toContain([400, 'social-cards', 'Social cards'])
        ->and($setting?->type)->toBe(SettingType::Media)
        ->and($setting?->label)->toBe('Default social image')
        ->and($setting?->description)->toBe('On the card when something is shared that has no image of its own: no social image, no featured image and none in its body. Archives and the home page use it too. 1200 × 630 pixels suits most services.')
        ->and(SocialCards::defaultImage())->toBeNull();
});

it('shows the default social image on an item with no image of its own, on the home page and on an archive, and none on a search (I-293)', function (): void {
    $default = pageImage($this->admin, 1200, 630);
    cmSettings([SocialCards::DEFAULT_IMAGE => $default->id], SearchAndSocial::KEY);
    $news = headTerm('category', 'News');
    headPost($this->author, 'Harbour news', [], [$news]);
    $served = ($this->served)($default);

    expect(($this->image)('/harbour-news/'))->toStartWith('http')->toEndWith($served)
        ->and(($this->image)('/'))->toEndWith($served)
        ->and(($this->image)('/category/news/'))->toEndWith($served)
        ->and(($this->image)('/?s=harbour'))->toBe('');
});

it('keeps an item\'s own image before the default (I-293)', function (): void {
    $default = pageImage($this->admin, 1200, 630);
    $featured = pageImage($this->author, 1400, 900);
    cmSettings([SocialCards::DEFAULT_IMAGE => $default->id], SearchAndSocial::KEY);
    headPost($this->author, 'Pictured', [], [], [], $featured);

    expect(($this->image)('/pictured/'))->toEndWith(($this->served)($featured));
});

it('draws no image once the default is in the trash, and a page that showed it carries its tag, so the change removes it', function (): void {
    $default = pageImage($this->admin, 1200, 630);
    cmSettings([SocialCards::DEFAULT_IMAGE => $default->id], SearchAndSocial::KEY);
    headPost($this->author, 'Harbour news');
    $this->folder = cacheOn();

    addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/harbour-news/')?->tags)->toContain("media:{$default->id}", 'seo');

    app(TrashMedia::class)->execute(new MediaActionInput($default->id, (string) $this->admin->id));

    expect(cacheKept('/harbour-news/'))->toBe([])
        ->and(($this->image)('/harbour-news/'))->toBe('');
});

it('makes the default image\'s card when someone chooses it, as them, counts it among the images that earn one, and queues nothing for a change no one made (I-855)', function (): void {
    $default = pageImage($this->admin, 1600, 900);
    $other = pageImage($this->admin, 1600, 900);
    Queue::fake();

    expect(ShareImageUse::wanted($default->id))->toBeFalse();

    app(UpdateSettings::class)->execute(new UpdateSettingsInput(SearchAndSocial::KEY, [SocialCards::DEFAULT_IMAGE => $default->id], [], [], 0, (string) $this->admin->id));

    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->mediaId === $default->id && $job->actor->userId === (string) $this->admin->id && $job->usedBy === null);

    expect(ShareImageUse::wanted($default->id))->toBeTrue()
        ->and(ShareImageUse::wanted($other->id))->toBeFalse()
        ->and(ShareImageUse::lacking(null, 10))->toContain($default->id)->not->toContain($other->id);

    Queue::fake();
    event(new SettingsChanged(SearchAndSocial::KEY, [SocialCards::DEFAULT_IMAGE], null));

    Queue::assertNothingPushed();
});
