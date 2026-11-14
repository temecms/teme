<?php

declare(strict_types=1);

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Redirects\OldRules;
use App\Domain\Import\Phases\Redirects\RegexPaths;

it('writes a regex rule in Teme’s form when it fits, its groups as placeholders in both addresses (I-287)', function (string $regex, string $target, array $converted): void {
    expect(RegexPaths::convert($regex, $target))->toBe($converted);
})->with([
    'the rest' => ['^/old/(.*)$', '/new/$1', ['from' => '/old/[...p1]', 'to' => '/new/[...p1]', 'widened' => false]],
    'parts, one narrower' => ['^/(\d+)/([^/]+)/?$', '/news/$2/', ['from' => '/[p1]/[p2]', 'to' => '/news/[p2]/', 'widened' => true]],
    'no leading slash' => ['^blog/([^/]+)$', '/news/${1}', ['from' => '/blog/[p1]', 'to' => '/news/[p1]', 'widened' => false]],
    'ending with the rest, unanchored' => ['^/shop/(.+)', 'https://shop.example/$1', ['from' => '/shop/[...p1]', 'to' => 'https://shop.example/[...p1]', 'widened' => false]],
    'an escaped dot' => ['^/old\.html$', '/new/', ['from' => '/old.html', 'to' => '/new/', 'widened' => false]],
    'a dot as written' => ['^/old.html$', '/new/', ['from' => '/old.html', 'to' => '/new/', 'widened' => false]],
    'escaped slashes and a backreference' => ['^\/news\/(.*)$', '/stories/\1', ['from' => '/news/[...p1]', 'to' => '/stories/[...p1]', 'widened' => false]],
    'a part with its slash escaped' => ['^\/topic\/([^\/]+)\/?$', '/subjects/$1/', ['from' => '/topic/[p1]', 'to' => '/subjects/[p1]/', 'widened' => false]],
    'a part that may be empty, escaped' => ['^/tag/([^\/]*)$', '/topics/$1', ['from' => '/tag/[p1]', 'to' => '/topics/[p1]', 'widened' => false]],
    'a dated rule, counted digits' => ['^/(\d{4})/(\d{2})/(.*)$', '/blog/$3', ['from' => '/[p1]/[p2]/[...p3]', 'to' => '/blog/[...p3]', 'widened' => true]],
    'counted digits as a class' => ['^/archive/([0-9]{4})/$', '/news/$1/', ['from' => '/archive/[p1]', 'to' => '/news/[p1]/', 'widened' => true]],
    'digits counted between two' => ['^/p/(\d{1,5})$', '/posts/$1', ['from' => '/p/[p1]', 'to' => '/posts/[p1]', 'widened' => true]],
]);

it('says in words why a regex rule can’t be written in Teme’s form, never running it', function (string $regex, string $target, string $reason): void {
    expect(RegexPaths::convert($regex, $target))->toBe($reason);
})->with([
    'unanchored' => ['old/(.*)', '/new/$1', 'it can match anywhere in an address, not only from the start'],
    'an alternation' => ['^/(a|b)/$', '/x/', 'a group in it matches in a way Teme can’t'],
    'a catastrophic group' => ['^/(a+)+$', '/x/', 'a group in it matches in a way Teme can’t'],
    'a group inside a part' => ['^/post-(\d+)$', '/p/$1', 'a group shares a part of the address with other text'],
    'text after a group' => ['^/(\d+)x$', '/p/$1', 'a group shares a part of the address with other text'],
    'a prefix only' => ['^/old/', '/new/', 'it matches the start of longer addresses too'],
    'the rest before the end' => ['^/(.*)/end$', '/x/', 'it matches the rest of an address before its end'],
    'a group it hasn’t' => ['^/x/([^/]+)$', '/y/$2', 'it sends readers to $2, which it doesn’t have'],
    'a query' => ['^/old\?id=(\d+)$', '/new/', 'it holds a ? or a #'],
    'a class outside a group' => ['^/[a-z]+$', '/x/', 'it uses [ outside a group'],
    'an escape Teme can’t read' => ['^/\d+$', '/x/', 'it uses \d outside a group'],
    'brackets that don’t close' => ['^/(.*$', '/x/', 'its brackets don’t close'],
    'too long' => ['^/'.str_repeat('a', 500), '/x/', 'it is longer than 500 characters'],
    'a source that is only a placeholder' => ['^/(.*)$', '/new/$1', RegexPaths::ANYWHERE],
    'a target here that is only a placeholder' => ['^/(\d{4})/(\d{2})/(.*)$', '/$3', RegexPaths::ANYWHERE],
    'a target without its slash, only a placeholder' => ['^/old/([^/]+)/?$', '$1', RegexPaths::ANYWHERE],
]);

it('reads each store’s rules as the redirects they become, and says what it couldn’t bring', function (string $store, mixed $item, int|string $key, string $id, array $redirects, array $codes): void {
    $rule = OldRules::read($store, $item, $key);

    expect($rule->ref->key())->toBe("redirect:{$id}")
        ->and($rule->redirects)->toBe($redirects)
        ->and(array_map(fn (ImportMessage $note): string => $note->code, $rule->notes))->toBe($codes);
})->with([
    'Redirection, a redirect' => ['redirection', ['id' => 1, 'url' => '/old-page/', 'match_type' => 'url', 'action_type' => 'url', 'action_code' => '301', 'action_data' => ['url' => '/new-page/'], 'regex' => '0', 'status' => 'enabled'], 0, 'redirection:1', [['from' => '/old-page/', 'to' => '/new-page/', 'status' => 301]], []],
    'Redirection, a target as text' => ['redirection', ['id' => 2, 'url' => '/a', 'action_type' => 'url', 'action_code' => 302, 'action_data' => '/x/', 'regex' => 0], 1, 'redirection:2', [['from' => '/a', 'to' => '/x/', 'status' => 302]], []],
    'Redirection, a regex' => ['redirection', ['id' => 3, 'url' => '^/old/(.*)$', 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url' => '/new/$1'], 'regex' => 1], 2, 'redirection:3', [['from' => '/old/[...p1]', 'to' => '/new/[...p1]', 'status' => 301]], []],
    'Redirection, switched off' => ['redirection', ['id' => 4, 'url' => '/b', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/c', 'status' => 'disabled'], 3, 'redirection:4', [], ['redirect.switched-off']],
    'Redirection, for signed-in visitors' => ['redirection', ['id' => 5, 'url' => '/d', 'match_type' => 'login', 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url_from' => '/e']], 4, 'redirection:5', [], ['redirect.conditional']],
    'Redirection, gone' => ['redirection', ['id' => 6, 'url' => '/gone', 'action_type' => 'error', 'action_code' => 410], 5, 'redirection:6', [['from' => '/gone', 'to' => '', 'status' => 410]], []],
    'Redirection, not found' => ['redirection', ['id' => 7, 'url' => '/nothing', 'action_type' => 'error', 'action_code' => 404], 6, 'redirection:7', [], ['redirect.not-found-rule']],
    'Redirection, passing through' => ['redirection', ['id' => 8, 'url' => '/proxy', 'action_type' => 'pass', 'action_code' => 200, 'action_data' => 'https://elsewhere.example/'], 7, 'redirection:8', [], ['redirect.action']],
    'Redirection, a query' => ['redirection', ['id' => 9, 'url' => '/old.php?id=4', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/new/'], 8, 'redirection:9', [], ['redirect.query']],
    'Redirection, a status Teme hasn’t' => ['redirection', ['id' => 10, 'url' => '/see', 'action_type' => 'url', 'action_code' => 303, 'action_data' => '/other/'], 9, 'redirection:10', [['from' => '/see', 'to' => '/other/', 'status' => 302]], ['redirect.status-changed']],
    'Rank Math, exact' => ['rank_math', ['id' => 4, 'sources' => [['pattern' => 'old-page', 'comparison' => 'exact']], 'url_to' => 'https://old.example/new-page/', 'header_code' => 301, 'status' => 'active'], 0, 'rank-math:4', [['from' => '/old-page', 'to' => 'https://old.example/new-page/', 'status' => 301]], []],
    'Rank Math, a start that ends a part' => ['rank_math', ['id' => 5, 'sources' => [['pattern' => 'shop/', 'comparison' => 'start']], 'url_to' => '/store/', 'header_code' => 301, 'status' => 'active'], 1, 'rank-math:5', [['from' => '/shop/', 'to' => '/store/', 'status' => 301], ['from' => '/shop/[...rest]', 'to' => '/store/', 'status' => 301]], []],
    'Rank Math, contains and a regex' => ['rank_math', ['id' => 6, 'sources' => [['pattern' => 'promo', 'comparison' => 'contains'], ['pattern' => '^deals/([^/]+)$', 'comparison' => 'regex']], 'url_to' => '/offers/$1', 'header_code' => 307, 'status' => 'active'], 2, 'rank-math:6', [['from' => '/deals/[p1]', 'to' => '/offers/[p1]', 'status' => 307]], ['redirect.comparison']],
    'Rank Math, inactive' => ['rank_math', ['id' => 7, 'sources' => [['pattern' => 'x', 'comparison' => 'exact']], 'url_to' => '/y/', 'header_code' => 301, 'status' => 'inactive'], 3, 'rank-math:7', [], ['redirect.switched-off']],
    'Safe Redirect Manager, a wildcard' => ['safe_redirect_manager', ['id' => 88, 'status' => 'publish', 'from' => '/old/*', 'to' => '/new/*', 'code' => 301, 'regex' => false], 0, 'safe-redirect-manager:88', [['from' => '/old/[...rest]', 'to' => '/new/[...rest]', 'status' => 301]], []],
    'Safe Redirect Manager, a regex that widens' => ['safe_redirect_manager', ['id' => 89, 'status' => 'publish', 'from' => '^/a/(\d+)$', 'to' => '/b/$1', 'code' => 302, 'regex' => true], 1, 'safe-redirect-manager:89', [['from' => '/a/[p1]', 'to' => '/b/[p1]', 'status' => 302]], ['redirect.widened']],
    'Safe Redirect Manager, a draft' => ['safe_redirect_manager', ['id' => 90, 'status' => 'draft', 'from' => '/c', 'to' => '/d', 'code' => 301, 'regex' => false], 2, 'safe-redirect-manager:90', [], ['redirect.switched-off']],
    'Yoast SEO Premium, plain' => ['yoast_premium', ['origin' => 'old-one', 'url' => 'new-one', 'type' => 301, 'format' => 'plain'], 0, 'yoast-premium:old-one', [['from' => '/old-one', 'to' => 'new-one', 'status' => 301]], []],
    'Yoast SEO Premium, a regex' => ['yoast_premium', ['origin' => '^blog/(.*)$', 'url' => 'news/$1', 'type' => 301, 'format' => 'regex'], 1, 'yoast-premium:^blog/(.*)$', [['from' => '/blog/[...p1]', 'to' => 'news/[...p1]', 'status' => 301]], []],
    'Simple 301 Redirects' => ['simple_301', 'https://old.example/new', '/legacy', 'simple-301:/legacy', [['from' => '/legacy', 'to' => 'https://old.example/new', 'status' => 301]], []],
    'Simple 301 Redirects, a wildcard inside a part' => ['simple_301', '/new', '/old*', 'simple-301:/old*', [], ['redirect.comparison']],
]);

it('says each refusal in words a person can act on', function (): void {
    $regex = OldRules::read('redirection', ['id' => 1, 'url' => '^/(a|b)/$', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/x/', 'regex' => 1], 0);
    $contains = OldRules::read('rank_math', ['id' => 2, 'sources' => [['pattern' => 'promo', 'comparison' => 'contains']], 'url_to' => '/x/', 'header_code' => 301, 'status' => 'active'], 0);
    $login = OldRules::read('redirection', ['id' => 3, 'url' => '/d', 'match_type' => 'login', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/e'], 0);
    $widened = OldRules::read('safe_redirect_manager', ['id' => 4, 'status' => 'publish', 'from' => '^/a/(\d+)$', 'to' => '/b/$1', 'code' => 301, 'regex' => true], 0);

    expect($regex->notes[0]->message)->toBe('Its pattern ^/(a|b)/$ can’t be written as Teme’s, because a group in it matches in a way Teme can’t. Add it in Tools › Redirects, with [name] for one part of an address and [...rest] for the rest.')
        ->and($contains->notes[0]->message)->toBe('It matches addresses that contain promo, which Teme’s redirects can’t, so it wasn’t brought.')
        ->and($login->notes[0]->message)->toBe('It redirects only some visitors (by login), which Teme’s redirects can’t, so it wasn’t brought.')
        ->and($widened->notes[0]->message)->toBe('Its pattern ^/a/(\d+)$ became /a/[p1], which takes any text in each part, not only what the pattern allowed.')
        ->and($regex->hash)->toBe(OldRules::read('redirection', ['id' => 1, 'url' => '^/(a|b)/$', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/x/', 'regex' => 1], 5)->hash)
        ->and($regex->hash)->not->toBe(OldRules::read('redirection', ['id' => 1, 'url' => '^/(a|c)/$', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/x/', 'regex' => 1], 0)->hash);
});
