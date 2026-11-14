<?php

declare(strict_types=1);

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Menus\MenuNode;
use App\Domain\Import\Phases\Menus\MenuShape;
use App\Domain\Import\Phases\Menus\MenuTargets;
use App\Domain\Import\Phases\Menus\WordPressMenus;
use Tests\Fixtures\Import\MenuImports;

/**
 * A found item labelled $label leading to $url, or a heading when $url is
 * null, with these items under it.
 *
 * @param  list<MenuNode>  $children
 */
function msFound(string $label, ?string $url = '/x/', array $children = []): MenuNode
{
    $node = new MenuNode(name: "“{$label}”", label: $label, ownLabel: true, leadsTo: MenuNode::ADDRESS, address: $url ?? '', children: $children);

    return $url === null ? $node->found(null, heading: true) : $node->found(['kind' => 'url', 'target_id' => null, 'url' => $url]);
}

/**
 * A found item that was left out, with these items under it.
 *
 * @param  list<MenuNode>  $children
 */
function msLeftOut(string $label, array $children = []): MenuNode
{
    return (new MenuNode(name: "“{$label}”", label: $label, ownLabel: true, leadsTo: MenuNode::ADDRESS, children: $children))->found(null);
}

/**
 * Read nodes as their labels, each with the labels under it.
 *
 * @param  list<MenuNode>  $nodes
 *
 * @return array<string, mixed>
 */
function msTree(array $nodes): array
{
    $tree = [];

    foreach ($nodes as $node) {
        $tree[$node->label] = msTree($node->children);
    }

    return $tree;
}

/**
 * A shaped tree as its labels: a label alone, or a label over the labels under it.
 *
 * @param  list<array<string, mixed>>  $tree
 *
 * @return list<mixed>
 */
function msLabels(array $tree): array
{
    return array_map(fn (array $row): mixed => $row['children'] === [] ? (string) $row['label'] : [(string) $row['label'] => msLabels($row['children'])], $tree);
}

/**
 * @param  list<ImportMessage>  $messages
 *
 * @return list<string>
 */
function msWords(array $messages): array
{
    return array_map(fn (ImportMessage $message): string => $message->message, $messages);
}

it('builds a classic menu’s tree from its parents and order, whatever order the items come in', function (): void {
    $read = (new WordPressMenus)->classic([
        MenuImports::item(103, ['parent' => 101, 'order' => 4, 'label' => 'Team']),
        MenuImports::item(102, ['order' => 2, 'label' => 'News']),
        MenuImports::item(101, ['order' => 1, 'label' => 'About']),
        MenuImports::item(104, ['parent' => 101, 'order' => 3, 'label' => 'History']),
    ]);

    expect(msTree($read['nodes']))->toBe(['About' => ['History' => [], 'Team' => []], 'News' => []])
        ->and($read['messages'])->toBe([]);
});

it('puts an item whose parent WordPress doesn’t list, and items that loop, at the top after the rest', function (): void {
    $read = (new WordPressMenus)->classic([
        MenuImports::item(101, ['label' => 'Home']),
        MenuImports::item(102, ['parent' => 999, 'label' => 'Shop']),
        MenuImports::item(103, ['parent' => 102, 'label' => 'Boats']),
        MenuImports::item(104, ['parent' => 105, 'label' => 'Ping']),
        MenuImports::item(105, ['parent' => 104, 'label' => 'Pong']),
        MenuImports::item(106, ['label' => 'Gone', 'invalid' => true]),
        MenuImports::item(107, ['parent' => 106, 'label' => 'Kept']),
    ]);

    expect(msTree($read['nodes']))->toBe(['Home' => [], 'Shop' => ['Boats' => []], 'Ping' => ['Pong' => []], 'Kept' => []])
        ->and(msWords($read['messages']))->toBe([
            'WordPress marked “Gone” as broken, since what it led to is gone, so it was left out.',
            '“Shop” sat under an item WordPress’s menu doesn’t show, so it’s at the top of the menu, as WordPress showed it.',
            '“Ping” sat under an item WordPress’s menu doesn’t show, so it’s at the top of the menu, as WordPress showed it.',
            '“Kept” sat under an item WordPress’s menu doesn’t show, so it’s at the top of the menu, as WordPress showed it.',
        ]);
});

it('reads a navigation post’s links, submenus, home link and page lists, and names the other blocks it leaves out', function (): void {
    $read = (new WordPressMenus)->blocks([
        MenuImports::block('core/home-link', ['label' => 'Start']),
        MenuImports::block(null),
        MenuImports::block('core/navigation-submenu', ['label' => 'Visit', 'kind' => 'post-type', 'type' => 'page', 'id' => 20, 'url' => 'https://old.example/visit/'], [
            MenuImports::block('core/navigation-link', ['label' => 'Ferries', 'kind' => 'taxonomy', 'type' => 'tag', 'id' => 8, 'url' => 'https://old.example/tag/ferries/', 'opensInNewTab' => true]),
            MenuImports::block('core/page-list', ['parentPageID' => 20]),
        ]),
        MenuImports::block('core/search', ['label' => 'Search']),
        MenuImports::block('core/social-links', [], [MenuImports::block('core/social-link', ['service' => 'mastodon'])]),
        MenuImports::block('core/social-links'),
    ]);
    [$start, $visit] = $read['nodes'];
    [$ferries, $pages] = $visit->children;

    expect($read['nodes'])->toHaveCount(2)
        ->and([$start->leadsTo, $start->label, $start->ownLabel])->toBe([MenuNode::HOME, 'Start', true])
        ->and([$visit->leadsTo, $visit->objectId, $visit->address])->toBe([MenuNode::ITEM, 20, 'https://old.example/visit/'])
        ->and([$ferries->leadsTo, $ferries->objectId, $ferries->taxonomy, $ferries->newTab])->toBe([MenuNode::TERM, 8, 'post_tag', true])
        ->and([$pages->leadsTo, $pages->objectId])->toBe([MenuNode::PAGES, 20])
        ->and(msWords($read['messages']))->toBe([
            'It also held 1 core/search block and 2 core/social-links blocks, which a Teme menu can’t hold, so they were left out.',
        ]);
});

it('leaves out a link with no label, and what’s under it, as WordPress draws neither', function (): void {
    $read = (new WordPressMenus)->blocks([
        MenuImports::block('core/navigation-submenu', ['label' => '<span></span>', 'url' => '/about/'], [
            MenuImports::block('core/navigation-link', ['label' => 'Team', 'url' => '/team/']),
        ]),
        MenuImports::block('core/navigation-link', ['label' => 'News', 'url' => '/news/']),
    ]);

    expect(msTree($read['nodes']))->toBe(['News' => []])
        ->and(msWords($read['messages']))->toBe(['A link with no label, which WordPress doesn’t show, was left out with anything under it.']);
});

it('keeps a label as plain words, as WordPress showed them', function (string $label, string $kept): void {
    expect(WordPressMenus::label($label)['text'])->toBe($kept);
})->with([
    'its tags removed' => ['<i class="fa fa-home"></i> <strong>Home</strong>', 'Home'],
    'escaping undone once' => ['Night &amp; Day &amp;amp; more', 'Night & Day &amp; more'],
    'spaces as one' => ["  Visit\n\tus  ", 'Visit us'],
    'cut to 200 characters' => [str_repeat('é', 250), str_repeat('é', 200)],
]);

it('says what Teme’s menus don’t keep', function (): void {
    $read = (new WordPressMenus)->classic([
        MenuImports::item(101, ['label' => '<b>Shop</b>', 'classes' => ['menu-button'], 'attr_title' => 'Our shop', 'xfn' => 'nofollow']),
        MenuImports::item(102, ['label' => 'Blog', 'classes' => ['', 'highlight']]),
    ]);

    expect($read['messages'])->toHaveCount(1)
        ->and($read['messages'][0]->code)->toBe('menu.extras')
        ->and($read['messages'][0]->message)->toBe('Teme’s menus keep each item’s label, where it leads and whether it opens a new tab, so these were left out: the CSS classes of 2 items, the link title of 1 item, the link relation of 1 item and the formatting of 1 label.')
        ->and($read['messages'][0]->context)->toBe(['classes' => 2, 'titles' => 1, 'relations' => 1, 'descriptions' => 0, 'formatting' => 1]);
});

it('lifts items more than three levels deep to the third level, after the item they sat under', function (): void {
    $shaped = MenuShape::tree([
        msFound('A', '/a/', [
            msFound('B', '/b/', [msFound('C', '/c/', [msFound('D', '/d/', [msFound('E', '/e/')]), msFound('F', '/f/')])]),
            msFound('G', '/g/'),
        ]),
    ]);

    expect(msLabels($shaped['tree']))->toBe([['A' => [['B' => ['C', 'D', 'E', 'F']], 'G']]])
        ->and(msWords($shaped['messages']))->toBe(['3 items sat more than three levels deep, so each sits at the third level, after the item above it: a Teme menu goes three levels deep.']);
});

it('keeps the first 200 items in reading order, naming the first one left out', function (): void {
    $nodes = array_map(fn (int $n): MenuNode => msFound("Item {$n}", "/{$n}/"), range(1, 199));
    $nodes[] = msFound('Item 200', '/200/', [msFound('Item 201', '/201/'), msFound('Item 202', '/202/')]);
    $nodes[] = msFound('Item 203', '/203/');

    $shaped = MenuShape::tree($nodes);

    expect($shaped['tree'])->toHaveCount(200)
        ->and($shaped['tree'][199]['label'])->toBe('Item 200')
        ->and($shaped['tree'][199]['children'])->toBe([])
        ->and(msWords($shaped['messages']))->toBe(['It has 203 items, and a Teme menu holds 200, so the last 3 were left out, from “Item 201” on.']);
});

it('keeps a heading at the cap only when an item under it is kept too', function (): void {
    $nodes = array_map(fn (int $n): MenuNode => msFound("Item {$n}", "/{$n}/"), range(1, 199));
    $nodes[] = msFound('Visit', null, [msFound('Inner', null, [msFound('Deep', '/deep/')])]);

    $shaped = MenuShape::tree($nodes);

    expect($shaped['tree'])->toHaveCount(199)
        ->and(array_column($shaped['tree'], 'kind'))->not->toContain('heading')
        ->and(msWords($shaped['messages']))->toBe(['It has 202 items, and a Teme menu holds 200, so the last 3 were left out, from “Visit” on.']);
});

it('makes an item with no address a heading over its items, and leaves out one with nothing under it or no label', function (): void {
    $unlabelled = (new MenuNode(name: 'an item with no label (WordPress ID 7)', label: '', ownLabel: true, leadsTo: MenuNode::ADDRESS, children: [msFound('Inside', '/inside/')]))->found(null, heading: true);

    $shaped = MenuShape::tree([
        msFound('Services', null, [msFound('Design', '/design/'), msFound('Print', '/print/')]),
        msFound('Spacer', null),
        msFound('Group', null, [msFound('Inner', null), msLeftOut('Script')]),
        $unlabelled,
    ]);

    expect($shaped['tree'][0])->toMatchArray(['kind' => 'heading', 'target_id' => null, 'url' => null, 'label' => 'Services'])
        ->and(msLabels($shaped['tree']))->toBe([['Services' => ['Design', 'Print']], 'Inside'])
        ->and(msWords($shaped['messages']))->toBe([
            '“Spacer” had no address and nothing under it to lead to, so it was left out.',
            '“Inner” had no address and nothing under it to lead to, so it was left out.',
            '“Group” had no address and nothing under it to lead to, so it was left out.',
            'An item with no label (WordPress ID 7) had no address and no label, so the items under it take its place.',
        ]);
});

it('never repeats an email address from a label in the report', function (): void {
    $read = (new WordPressMenus)->classic([
        MenuImports::item(101, ['parent' => 999, 'label' => 'Write to hello@old.example']),
        MenuImports::item(102, ['parent' => 999, 'label' => '']),
    ]);

    expect(msWords($read['messages']))->toBe([
        'An item labelled with an email address (WordPress ID 101) sat under an item WordPress’s menu doesn’t show, so it’s at the top of the menu, as WordPress showed it.',
        'An item with no label (WordPress ID 102) sat under an item WordPress’s menu doesn’t show, so it’s at the top of the menu, as WordPress showed it.',
    ])
        ->and(WordPressMenus::named('Write to hello@old.example', 'none', 'hidden'))->toBe('hidden')
        ->and(WordPressMenus::named('Visit us @ the harbour', 'none', 'hidden'))->toBe('“Visit us @ the harbour”');
});

it('gives the place of an item left out to the items under it', function (): void {
    $shaped = MenuShape::tree([
        msFound('Home', '/'),
        msLeftOut('Script', [msFound('Boats', '/boats/'), msFound('Ferries', '/ferries/')]),
        msFound('Contact', '/contact/'),
    ]);

    expect(msLabels($shaped['tree']))->toBe(['Home', 'Boats', 'Ferries', 'Contact'])
        ->and($shaped['messages'])->toBe([]);
});

it('lets an item by ID follow its target’s title unless WordPress gave it a label of its own', function (): void {
    $about = new MenuNode(name: '“About”', label: 'About', ownLabel: false, leadsTo: MenuNode::ITEM, objectId: 20);
    $ours = new MenuNode(name: '“Ours”', label: 'Ours', ownLabel: true, leadsTo: MenuNode::ITEM, objectId: 20);
    $byId = ['kind' => 'content', 'target_id' => '01jb0000000000000000000001', 'url' => null];

    $shaped = MenuShape::tree([$about->found($byId), $about->found(['kind' => 'url', 'target_id' => null, 'url' => '/about/']), $ours->found($byId)]);

    expect(array_column($shaped['tree'], 'label'))->toBe([null, 'About', 'Ours']);
});

it('keeps an address as a menu can hold it, the old site’s own as a path here', function (string $address, array $kept): void {
    expect(MenuTargets::address($address, new ConversionSite('https://old.example')))->toBe($kept);
})->with([
    'the old site, with www.' => ['https://www.old.example/visit/', [MenuTargets::OWN, '/visit/']],
    'the old site, plain http' => ['http://old.example/visit/', [MenuTargets::OWN, '/visit/']],
    'the old site, no scheme' => ['//old.example/visit/', [MenuTargets::OWN, '/visit/']],
    'its home' => ['https://old.example', [MenuTargets::OWN, '/']],
    'a query and a place' => ['https://old.example/?s=boats#results', [MenuTargets::OWN, '/?s=boats#results']],
    'a path' => ['/visit/', [MenuTargets::OWN, '/visit/']],
    'a space' => ['https://old.example/our visit/', [MenuTargets::OWN, '/our%20visit/']],
    'another site' => ['https://partner.example/a', [MenuTargets::OTHER, 'https://partner.example/a']],
    'another site, no scheme' => ['//cdn.partner.example/a', [MenuTargets::OTHER, 'https://cdn.partner.example/a']],
    'an email' => ['mailto:hello@example.org', [MenuTargets::OTHER, 'mailto:hello@example.org']],
    'only a place on the page' => ['#contact', [MenuTargets::NONE, '']],
    'nothing' => ['#', [MenuTargets::NONE, '']],
    'a script' => ['javascript:alert(1)', [MenuTargets::REFUSED, '']],
    'relative to the page' => ['contact/', [MenuTargets::REFUSED, '']],
    'a user name on the old host' => ['https://u@old.example/', [MenuTargets::REFUSED, '']],
    'Jetpack’s copy of an old file' => ['https://i0.wp.com/old.example/x.pdf', [MenuTargets::REFUSED, '']],
]);
