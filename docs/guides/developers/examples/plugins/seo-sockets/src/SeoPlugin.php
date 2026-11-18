<?php

declare(strict_types=1);

namespace TemeExamples\SeoSockets;

use Teme\Contracts\Editor\CheckedItem;
use Teme\Contracts\Editor\CheckResult;
use Teme\Contracts\Editor\HasEditorChecks;
use Teme\Contracts\Head\HasHeadMeta;
use Teme\Contracts\Head\HeadMeta;
use Teme\Contracts\Head\HeadPage;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Sitemaps\HasSitemaps;
use Teme\Contracts\Sitemaps\PluginSitemap;
use Teme\Contracts\Sitemaps\SitemapEntry;
use Teme\Contracts\StructuredData\GraphNode;
use Teme\Contracts\StructuredData\HasStructuredData;

/**
 * The handbook's SEO example: every socket an SEO plugin plugs into, each
 * answering from what core hands it and nothing else.
 */
final class SeoPlugin implements HasEditorChecks, HasHeadMeta, HasSitemaps, HasStructuredData, Plugin
{
    public function register(Hooks $hooks): void {}

    public function checkItem(CheckedItem $item): array
    {
        $focus = $item->own['focus'] ?? null;

        if (! is_string($focus) || $focus === '') {
            return [new CheckResult('keyphrase', CheckResult::IMPROVE, 'Add a focus keyphrase.', ownField: 'focus')];
        }

        return str_contains(mb_strtolower($item->title), mb_strtolower($focus))
            ? [new CheckResult('keyphrase', CheckResult::OK, 'The title uses the focus keyphrase.')]
            : [new CheckResult('keyphrase', CheckResult::PROBLEM, 'The title doesn’t use the focus keyphrase.', field: 'seo_title')];
    }

    public function headMeta(HeadPage $page): array
    {
        return ($page->own['noindex'] ?? false) === true ? [HeadMeta::name('robots', 'noindex, follow')] : [];
    }

    public function graphNodes(HeadPage $page): array
    {
        $nodes = [];

        foreach ($page->faqs as $faq) {
            $questions = [];

            foreach ($faq->pairs as $pair) {
                $questions[] = ['@type' => 'Question', 'name' => $pair->question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair->answerHtml]];
            }

            $nodes[] = new GraphNode(['@id' => $faq->nodeId, '@type' => 'FAQPage', 'mainEntity' => $questions]);
        }

        return $nodes;
    }

    public function sitemaps(): array
    {
        return ['landing' => new class implements PluginSitemap
        {
            public function starts(): array
            {
                return [];
            }

            public function entries(?string $from): array
            {
                return [SitemapEntry::path('/start-here/'), SitemapEntry::path('/pricing/')];
            }
        }];
    }
}
