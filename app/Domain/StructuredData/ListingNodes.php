<?php

/**
 * A listing's nodes (I-701): a term's, a date's or the latest posts'
 * collection page, an author's profile page with the person it is about,
 * a search's results; never a list of the items it shows, which Google
 * calls aggregation.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Languages\PageLanguage;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Head\HeadValues;
use App\Domain\Site\ViewModels\Crumb;
use App\Domain\Site\ViewModels\ListingView;
use Teme\Contracts\StructuredData\NodeIds;

final readonly class ListingNodes
{
    public function __construct(private PageLanguage $language) {}

    /**
     * The listing's nodes, or none while it has no address of its own.
     *
     * @param  list<Crumb>|null  $trail
     *
     * @return list<JsonLdNode>
     */
    public function for(ListingView $view, ?string $kind, HeadValues $values, ?array $trail = null): array
    {
        $page = $values->url;

        if ($page === null) {
            return [];
        }

        $person = null;
        $subject = $view->subject;
        $trailNode = TrailNode::for($trail, $page);

        if ($kind === 'author' && $subject !== null && $subject->url !== null) {
            $address = GraphAddresses::absolute($subject->url);
            $person = new JsonLdNode(['@id' => $address.NodeIds::PERSON, '@type' => 'Person', 'name' => $subject->title, 'url' => $address, 'description' => $subject->description]);
        }

        $nodes = [new JsonLdNode([
            '@id' => $page,
            '@type' => match ($kind) {
                'search' => ['CollectionPage', 'SearchResultsPage'],
                'author' => 'ProfilePage',
                default => 'CollectionPage',
            },
            'url' => $page,
            'name' => $values->title,
            'description' => $values->description,
            'isPartOf' => ['@id' => GraphAddresses::home().NodeIds::WEBSITE],
            'inLanguage' => $this->language->tag(),
            'mainEntity' => $person === null ? null : ['@id' => $person->id()],
            'breadcrumb' => $trailNode === null ? null : ['@id' => $trailNode->id()],
        ])];

        if ($person !== null) {
            $nodes[] = $person;
        }

        if ($trailNode !== null) {
            $nodes[] = $trailNode;
        }

        return $nodes;
    }
}
