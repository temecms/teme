<?php

declare(strict_types=1);

use App\Domain\Feeds\Xml;
use Carbon\CarbonImmutable;

it('escapes text and attributes as XML needs them, quotes in attributes only', function (): void {
    expect(Xml::text('Fish & "chips" <b>tonight</b> it\'s'))->toBe('Fish &amp; "chips" &lt;b&gt;tonight&lt;/b&gt; it\'s')
        ->and(Xml::attribute('Fish & "chips" <b> it\'s'))->toBe('Fish &amp; &quot;chips&quot; &lt;b&gt; it&apos;s')
        ->and(Xml::text('&amp;'))->toBe('&amp;amp;');
});

it('strips what XML 1.0 cannot carry, keeps tab, line feed and every real character, and turns invalid bytes into a question mark', function (): void {
    expect(Xml::clean("a\x00b\x08c\x0Bd\x0Ce\x1Ff\u{FFFE}g\u{FFFF}h"))->toBe('abcdefgh')
        ->and(Xml::clean("tab\there\nnew \u{1F600} café"))->toBe("tab\there\nnew \u{1F600} café")
        ->and(Xml::clean("caf\xE9"))->toBe('caf?')
        ->and(Xml::text("a\x08b"))->toBe('ab');
});

it('wraps HTML in CDATA so a ]]> inside it cannot end the section, and the document parses back to the same text', function (): void {
    $html = "<p>Tips: a[b[0]]>c and ]]> and ]] > </p>\n<p>emoji \u{1F600} &amp; \"quotes\"</p>";
    $document = new DOMDocument;

    expect(Xml::cdata('plain'))->toBe('<![CDATA[plain]]>')
        ->and(Xml::cdata('a]]>b'))->toBe('<![CDATA[a]]]]><![CDATA[>b]]>')
        ->and($document->loadXML('<?xml version="1.0" encoding="UTF-8"?><root>'.Xml::cdata($html).'</root>'))->toBeTrue()
        ->and($document->documentElement?->textContent)->toBe($html);
});

it('writes a moment in RFC 822, in UTC, with English day and month names whatever the language', function (): void {
    app()->setLocale('fr');

    expect(Xml::date(new DateTimeImmutable('2026-10-05 09:30:00', new DateTimeZone('Africa/Lagos'))))->toBe('Mon, 05 Oct 2026 08:30:00 +0000')
        ->and(Xml::date(CarbonImmutable::parse('2026-03-08 23:59:59', 'UTC')))->toBe('Sun, 08 Mar 2026 23:59:59 +0000');
});
