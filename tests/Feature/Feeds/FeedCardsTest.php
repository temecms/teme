<?php

declare(strict_types=1);

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\Cards\PlainCallout;
use App\Domain\Feeds\FeedBody;
use App\Domain\Feeds\FeedCards;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // A fake disk's addresses are relative (`/storage/…`), as a local disk's are without a `url`: the feed has addresses to make absolute.
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->body = fn (array ...$nodes): string => app(FeedBody::class)->html(['type' => 'doc', 'content' => $nodes]);
});

it('writes a callout, code, a button and an embed as their plain versions (I-861)', function (string $card, string $expected): void {
    expect(($this->body)(regionCardNode($card)))->toBe($expected);
})->with([
    'a callout is a paragraph' => ['callout', '<p>Bring a coat: it&#039;s cold by the water.</p>'],
    'code stays code' => ['snippet', '<figure><pre><code>if (a &lt; b) { return &quot;yes&quot;; }</code></pre><figcaption>A comparison</figcaption></figure>'],
    'a button is a link' => ['button', '<p><a href="https://example.com/tickets?day=1&amp;seat=2">Buy tickets</a></p>'],
    'an embed is a link to the original' => ['embed', '<figure><p><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">Watch on YouTube: The festival&#039;s opening night</a></p><figcaption>Filmed from the quay</figcaption></figure>'],
]);

it('words an embed by its kind and its provider, and a bookmark by its title or its address', function (): void {
    $embed = regionCardNode('embed');
    $with = fn (array $attrs): string => ($this->body)([...$embed, 'attrs' => [...$embed['attrs'], 'caption' => null, 'title' => null, ...$attrs]]);

    expect($with(['kind' => 'audio', 'provider' => 'spotify', 'url' => 'https://open.spotify.com/track/abc']))->toBe('<figure><p><a href="https://open.spotify.com/track/abc">Listen on Spotify</a></p></figure>')
        ->and($with(['kind' => 'social', 'provider' => 'mastodon', 'url' => 'https://www.social.example/@jane/1']))->toBe('<figure><p><a href="https://www.social.example/@jane/1">See it on social.example</a></p></figure>')
        ->and($with(['kind' => 'link', 'title' => 'The programme', 'url' => 'https://example.com/programme']))->toBe('<figure><p><a href="https://example.com/programme">The programme</a></p></figure>')
        ->and($with(['kind' => 'link', 'url' => 'https://example.com/programme']))->toBe('<figure><p><a href="https://example.com/programme">https://example.com/programme</a></p></figure>')
        ->and($with(['url' => 'javascript:alert(1)']))->toBe('');
});

it('writes an image as the image with its caption and credit, at an absolute address, with none of the page\'s attributes', function (): void {
    $node = pageImageNode(pageImage($this->author, 1600, 900), (string) Str::ulid());
    $node['attrs'] = [...$node['attrs'], 'alt' => 'The harbour\'s boats at dawn', 'caption' => 'Morning on the quay', 'credit' => 'Harbour Trust'];
    $dom = dtDom('<meta charset="utf-8">'.($this->body)($node));

    expect($dom->evaluate('count(//figure/img)'))->toBe(1.0)
        ->and((string) $dom->evaluate('string(//figure/img/@alt)'))->toBe('The harbour\'s boats at dawn')
        ->and((string) $dom->evaluate('string(//figure/img/@src)'))->toStartWith(rtrim(url('/'), '/').'/storage/')
        ->and((string) $dom->evaluate('string(//figure/img/@width)'))->toBe('960')
        ->and((string) $dom->evaluate('string(//figure/img/@height)'))->toBe('540')
        ->and($dom->evaluate('count(//figure/img/@srcset | //figure/img/@sizes | //figure/img/@loading | //figure/img/@class | //figure/img/@style)'))->toBe(0.0)
        ->and((string) $dom->evaluate('string(//figure/figcaption)'))->toBe('Morning on the quay Harbour Trust')
        ->and((string) $dom->evaluate('string(//figure/figcaption/small)'))->toBe('Harbour Trust');
});

it('writes a decorative image with an empty alt, and no figcaption when it has neither caption nor credit', function (): void {
    $node = pageImageNode(pageImage($this->author, 800, 600), (string) Str::ulid());
    $node['attrs'] = [...$node['attrs'], 'alt' => 'Ignored', 'decorative' => true];
    $dom = dtDom('<meta charset="utf-8">'.($this->body)($node));

    expect($dom->evaluate('count(//figure/img[@alt=""])'))->toBe(1.0)
        ->and($dom->evaluate('count(//figcaption)'))->toBe(0.0);
});

it('writes a gallery as its images, one to a paragraph, with its caption, and skips a picture that has gone', function (): void {
    $one = pageImage($this->author, 1600, 900);
    $two = pageImage($this->author, 1200, 800);
    $dom = dtDom('<meta charset="utf-8">'.($this->body)(['type' => 'gallery', 'attrs' => ['id' => (string) Str::ulid(), 'mediaIds' => [$one->id, (string) Str::ulid(), $two->id], 'caption' => 'Two views', 'layout' => 'grid']]));

    expect($dom->evaluate('count(//figure/p/img)'))->toBe(2.0)
        ->and((string) $dom->evaluate('string(//figure/figcaption)'))->toBe('Two views')
        ->and((string) $dom->evaluate('string(//figure/p[1]/img/@src)'))->toStartWith(rtrim(url('/'), '/').'/storage/');
});

it('writes nothing for an image or a gallery whose pictures have gone', function (): void {
    $gone = (string) Str::ulid();
    $image = ['type' => 'image', 'attrs' => ['id' => (string) Str::ulid(), 'mediaId' => $gone, 'alt' => null, 'decorative' => false, 'caption' => 'Lost', 'credit' => null, 'width' => 'column']];
    $gallery = ['type' => 'gallery', 'attrs' => ['id' => (string) Str::ulid(), 'mediaIds' => [$gone, (string) Str::ulid()], 'caption' => 'None here', 'layout' => 'row']];

    expect(($this->body)($image))->toBe('')
        ->and(($this->body)($gallery))->toBe('');
});

it('makes a button\'s article address absolute, and writes nothing for an article that has gone', function (): void {
    $live = addrPublished($this->author, 'Other story');
    $gone = addrPublished($this->author, 'Gone story');
    pageTrash($gone, $this->editor);
    $node = regionCardNode('button');
    $button = fn (string $id): array => [...$node, 'attrs' => [...$node['attrs'], 'contentId' => $id, 'url' => null]];

    expect(($this->body)($button($live->id)))->toBe('<p><a href="'.rtrim(url('/'), '/').'/other-story/">Buy tickets</a></p>')
        ->and(($this->body)($button($gone->id)))->toBe('');
});

it('draws prose as the page does, and a table stays a table (I-861)', function (string $fixture, string $needle): void {
    $document = json_decode((string) file_get_contents(base_path("tests/Fixtures/Documents/{$fixture}.json")), true);
    $html = app(FeedBody::class)->html($document);

    expect($html)->toContain($needle)->toBe(app(DocumentRenderer::class)->html($document));
})->with([
    'prose' => ['prose', '<h2 id="food-drink">Food &amp; drink</h2>'],
    'a table' => ['table-align', '<table>'],
]);

it('makes an internal link absolute, and leaves an item without an address as plain text', function (): void {
    $live = addrPublished($this->author, 'Other story');
    $draft = lifecycleDraft($this->author, 'Not yet');

    expect(($this->body)(schedLinkTo($live->id, 'the other story'), schedLinkTo($draft->id, 'the draft')))
        ->toBe('<p><a href="'.rtrim(url('/'), '/').'/other-story/">the other story</a></p><p>the draft</p>');
});

it('keeps prose and cards in the order the writer set them', function (): void {
    $html = ($this->body)(
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before']]],
        regionCardNode('callout'),
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After']]],
    );

    expect($html)->toBe('<p>Before</p><p>Bring a coat: it&#039;s cold by the water.</p><p>After</p>');
});

it('gives a card with no plain version its own words as a paragraph, and nothing when it has none', function (): void {
    $poll = Mockery::mock(CardType::class);
    $poll->shouldReceive('name')->andReturn('poll');
    $poll->shouldReceive('plainText')->andReturn('Pick one & vote');
    $silent = Mockery::mock(CardType::class);
    $silent->shouldReceive('name')->andReturn('chart');
    $silent->shouldReceive('plainText')->andReturn('  ');
    $cards = app(FeedCards::class);
    $context = new RenderContext(app(LinkResolver::class));

    expect($cards->has('poll'))->toBeFalse()
        ->and($cards->html($poll, [], $context))->toBe('<p>Pick one &amp; vote</p>')
        ->and($cards->html($silent, [], $context))->toBe('');
});

it('has a plain version for every card core registers, so the next card cannot ship without one (I-861)', function (): void {
    $cards = app(FeedCards::class);
    $missing = array_values(array_filter(array_map(fn (CardType $card): string => $card->name(), app(CardRegistry::class)->all()), fn (string $name): bool => ! $cards->has($name)));

    expect($missing)->toBe([])
        ->and(app(CardRegistry::class)->names())->not->toBe([]);
});

it('refuses two versions for one card, and something registered as a version that is not one', function (): void {
    expect(fn () => new FeedCards([new PlainCallout, new PlainCallout]))->toThrow(InvalidArgumentException::class, 'Two feed versions are written for the card [callout].')
        ->and(fn () => new FeedCards([new stdClass]))->toThrow(UnexpectedValueException::class, 'Registered as a FeedCardRenderer, but stdClass isn\'t one.');
});
