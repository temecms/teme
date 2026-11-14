<?php

declare(strict_types=1);

use App\Domain\Redirects\RedirectRefused;
use App\Domain\Redirects\RedirectSource;
use App\Domain\Redirects\RedirectTarget;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'https://ledger.example']);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('reads a source in the form the path table keeps addresses in (I-297)', function (string $typed, string $path, bool $pattern): void {
    $source = RedirectSource::read($typed);

    expect([$source->path, $source->isPattern, $source->shown()])->toBe([$path, $pattern, '/'.$path]);
})->with([
    'a plain path' => ['/old-fares', 'old-fares', false],
    'capitals and a trailing slash' => ['/Old-Fares/', 'old-fares', false],
    'doubled slashes' => ['/news//old-fares/', 'news/old-fares', false],
    'percent-encoded letters' => ['/caf%C3%A9-menu', 'café-menu', false],
    'the site\'s own address in full' => ['https://Ledger.example/old-fares/', 'old-fares', false],
    'a pattern' => ['/2019/05/[slug]', '2019/05/[slug]', true],
    'a pattern for the rest' => ['/blog/[...rest]', 'blog/[...rest]', true],
]);

it('compiles a pattern once into a regex PHP and PostgreSQL read alike (I-287)', function (): void {
    $year = RedirectSource::read('/2019/05/[slug]');
    $rest = RedirectSource::read('/blog/[...rest]');
    $dotted = RedirectSource::read('/files/[name]/v1.2');
    $matches = fn (string $path, string $regex): bool => (bool) DB::selectOne('SELECT ?::text ~ ?::text AS hit', [$path, $regex])->hit;

    expect($year->regex)->toBe('^2019/05/([^/]+)$')
        ->and($year->names)->toBe(['slug'])
        ->and($year->matches('2019/05/ferry-fares'))->toBe(['slug' => 'ferry-fares'])
        ->and($year->matches('2019/05/ferry/fares'))->toBeNull()
        ->and($rest->matches('blog/2020/ferry'))->toBe(['rest' => '2020/ferry'])
        ->and($rest->matches('blog'))->toBeNull()
        ->and($dotted->regex)->toBe('^files/([^/]+)/v1\.2$')
        ->and($dotted->matches('files/report/v1x2'))->toBeNull()
        ->and($matches('files/report/v1.2', $dotted->regex))->toBeTrue()
        ->and($matches('files/report/v1x2', $dotted->regex))->toBeFalse()
        ->and(RedirectSource::read('/old-fares')->matches('old-fares'))->toBe([])
        ->and(RedirectSource::sample('blog/[...rest]/[x]'))->toBe('blog/teme-sample/teme-more/teme-sample');
});

it('refuses a source no redirect can have, in its own words', function (string $typed, string $words): void {
    expect(fn () => RedirectSource::read($typed))->toThrow(fn (RedirectRefused $refused) => expect([$refused->field, $refused->getMessage()])->toBe(['source', $words]));
})->with([
    'nothing' => ['  ', 'Enter the address to redirect from, starting /.'],
    'another site' => ['https://elsewhere.example/old', 'A redirect starts on this site. Enter just the path, starting /.'],
    'no slash' => ['old-fares', 'Start the address with /, as in /old-page.'],
    'a query' => ['/old?id=4', 'Enter the path alone, without ? or # and what follows.'],
    'an encoded line break' => ['/old%0Afares', 'That address holds characters no address can.'],
    'the home page' => ['/', 'The home page always answers, so a redirect from / would send nobody on. Enter another address.'],
    'the admin' => ['/admin/posts', 'The address /admin/posts/ is used by the site itself.'],
    'a placeholder that isn\'t one' => ['/2019/[1slug]', 'Write each placeholder as [name] or [...rest], in lower-case letters.'],
    'half a part' => ['/2019/post-[slug]', 'A placeholder takes a whole part of the address, between two slashes, as in /2019/[name].'],
    'the rest in the middle' => ['/[...rest]/feed', '[...rest] takes everything after it, so it can only end the address.'],
    'a name twice' => ['/[slug]/[slug]', 'Each placeholder needs a name of its own: [slug] is used twice.'],
    'too long' => ['/'.str_repeat('a', 2001), 'Enter an address of 2,000 characters or fewer.'],
]);

it('reads where a redirect sends readers: a path on this site, another site, an item or a file (I-694, I-860)', function (): void {
    $from = RedirectSource::read('/old-fares');
    $item = addrPublished($this->editor, 'Ferry fares');
    $file = mediaUpload($this->editor, mediaPdf(), 'Timetable.pdf');

    $path = RedirectTarget::read('/news/Ferry%20fares/?ref=old#top', null, null, $from);
    $outside = RedirectTarget::read('https://podcasts.example.net/ledger', null, null, $from);
    $byId = RedirectTarget::read('', $item->id, null, $from);
    $byFile = RedirectTarget::read('', null, $file->id, $from);

    expect([$path->kind, $path->target, $path->path])->toBe(['address', '/news/Ferry%20fares/?ref=old#top', 'news/ferry fares'])
        ->and([$outside->kind, $outside->target, $outside->path, $outside->described()])->toBe(['outside', 'https://podcasts.example.net/ledger', null, 'https://podcasts.example.net/ledger'])
        ->and([$byId->kind, $byId->target, $byId->described()])->toBe(['content', $item->id, 'item:'.$item->id])
        ->and([$byFile->kind, $byFile->target, $byFile->described()])->toBe(['media', $file->id, 'file:'.$file->id]);
});

it('keeps the site\'s own address as a path, and an item\'s address, current or old, as the item (rule 9)', function (): void {
    $from = RedirectSource::read('/old-fares');
    $item = addrPublished($this->editor, 'Ferry fares');
    addrRename($item, 'ferry-fares-2026', $this->editor);

    $own = RedirectTarget::read('https://LEDGER.example/news/', null, null, $from);
    $current = RedirectTarget::read('/ferry-fares-2026/', null, null, $from);
    $old = RedirectTarget::read('https://ledger.example/ferry-fares', null, null, $from);
    $withQuery = RedirectTarget::read('/ferry-fares-2026/?from=print', null, null, $from);

    expect([$own->kind, $own->target, $own->path])->toBe(['address', '/news/', 'news'])
        ->and([$current->kind, $current->target])->toBe(['content', $item->id])
        ->and([$old->kind, $old->target])->toBe(['content', $item->id])
        ->and([$withQuery->kind, $withQuery->target])->toBe(['address', '/ferry-fares-2026/?from=print']);
});

it('refuses a target no redirect can have, in its own words (I-694)', function (string $to, string $words): void {
    $from = RedirectSource::read('/2019/[slug]');

    expect(fn () => RedirectTarget::read($to, null, null, $from))->toThrow(fn (RedirectRefused $refused) => expect([$refused->field, $refused->getMessage()])->toBe(['to', $words]));
})->with([
    'nothing' => ['', 'Enter the address to send readers to.'],
    'two slashes' => ['//evil.example/x', 'An address can’t start with // or /\\, because browsers read those as another site. For another site, write the full address, starting https://.'],
    'a slash and a backslash' => ['/\\evil.example', 'An address can’t start with // or /\\, because browsers read those as another site. For another site, write the full address, starting https://.'],
    'two slashes after the site\'s own address' => ['https://ledger.example//evil.example', 'An address can’t start with // or /\\, because browsers read those as another site. For another site, write the full address, starting https://.'],
    'a tab inside' => ["/\t/evil.example", 'That address holds characters no address can.'],
    'an encoded line break' => ['/news%0A/x', 'That address holds characters no address can.'],
    'a script' => ['javascript:alert(1)', 'Enter a path on this site, starting /, or a full address, starting https://.'],
    'no slash' => ['news/x', 'Enter a path on this site, starting /, or a full address, starting https://.'],
    'a placeholder the source lacks' => ['/news/[rest]', 'To uses [rest], which From doesn’t have.'],
]);

it('refuses an item or a file that isn\'t there, and a term\'s old address, naming where it went', function (): void {
    $from = RedirectSource::read('/old-fares');
    $news = typesTerm($this->editor, 'category', 'News');
    DB::table('paths')->insert(['path' => 'topics/old-news', 'target_type' => 'term', 'target_id' => $news->id, 'is_current' => false, 'replaced_at' => now()]);

    expect(fn () => RedirectTarget::read('', '01jvm0000000000000000000aa', null, $from))->toThrow(fn (RedirectRefused $refused) => expect([$refused->field, $refused->getMessage()])->toBe(['item', 'Choose the post or page to send readers to.']))
        ->and(fn () => RedirectTarget::read('', null, '01JVM0000000000000000000AA', $from))->toThrow(fn (RedirectRefused $refused) => expect([$refused->field, $refused->getMessage()])->toBe(['file', 'Choose the file to send readers to.']))
        ->and(fn () => RedirectTarget::read('/topics/old-news/', null, null, $from))->toThrow(RedirectRefused::class, 'The address /topics/old-news/ has moved to /category/news/. Send readers there directly, so they take one step, not two.');
});
