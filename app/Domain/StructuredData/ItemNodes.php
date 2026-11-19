<?php

/**
 * An item's nodes (I-701): the page it is drawn on, its main entity
 * when its type gives one, a person for each author with an address, its
 * primary image, its trail and its cards' nodes, from the head's values
 * and the revision the page shows.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Head\HeadValues;
use App\Domain\Site\SiteLanguage;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\Person;
use App\Domain\Site\ViewModels\TermLink;
use Teme\Contracts\StructuredData\NodeIds;

final readonly class ItemNodes
{
    public function __construct(
        private TypeSchemas $schemas,
        private SiteLanguage $language,
        private ImageObjects $images,
        private PropertyMap $map,
        private CardNodes $cards,
        private PublisherNodes $publisher,
    ) {}

    /**
     * The item's nodes, or none while it has no address.
     *
     * @return list<JsonLdNode>
     */
    public function for(ArticleView $view, HeadValues $values): array
    {
        $page = $values->url;

        if ($page === null) {
            return [];
        }

        $schema = $this->schemas->for($view->type);
        $language = $this->language->forContent($view->id);
        $published = $view->published->format(DATE_ATOM);
        $modified = ($view->revised !== null && $view->revised > $view->published ? $view->revised : $view->published)->format(DATE_ATOM);
        $image = $this->image($view);
        $imageRef = $image === null ? null : ['@id' => $page.NodeIds::PRIMARY_IMAGE];
        $trail = TrailNode::for($view->breadcrumbs, $page);

        $pageNode = [
            '@id' => $page,
            '@type' => $this->schemas->pageType($view->type, $view->fields),
            'url' => $page,
            'name' => $values->title,
            'description' => $values->description,
            'isPartOf' => ['@id' => GraphAddresses::home().NodeIds::WEBSITE],
            'inLanguage' => $language,
            'datePublished' => $published,
            'dateModified' => $modified,
            'primaryImageOfPage' => $imageRef,
            'breadcrumb' => $trail === null ? null : ['@id' => $trail->id()],
        ];

        $entity = $this->schemas->mainEntity($view->type, $view->fields);
        $entityNode = null;
        $people = [];

        if ($entity !== null && in_array($entity, TypeSchema::ARTICLES, true)) {
            [$authors, $people] = $this->authors($view->authors);

            $entityNode = [
                '@id' => $page.NodeIds::ARTICLE,
                '@type' => $entity,
                'isPartOf' => ['@id' => $page],
                'mainEntityOfPage' => ['@id' => $page],
                'headline' => $view->title,
                'datePublished' => $published,
                'dateModified' => $modified,
                'author' => $authors,
                'publisher' => $this->publisher->for($view)['reference'],
                'wordCount' => $view->wordCount,
                'keywords' => array_map(fn (TermLink $tag): string => $tag->name, $view->tags),
                'articleSection' => $view->kicker?->name,
                'image' => $imageRef === null ? null : [$imageRef, ...$image['crops']],
                'inLanguage' => $language,
            ];
        } elseif ($entity !== null) {
            // Not an article: its name alone, and its type's property map says the rest (I-709).
            $entityNode = ['@id' => $page.NodeIds::ARTICLE, '@type' => $entity, 'name' => $view->title, 'mainEntityOfPage' => ['@id' => $page]];
        }

        if ($entityNode !== null) {
            $entityNode = $this->map->apply($entityNode, $schema, $view->fields);
        } else {
            $pageNode = $this->map->apply($pageNode, $schema, $view->fields);
        }

        $nodes = [new JsonLdNode($pageNode)];

        if ($entityNode !== null) {
            $nodes[] = new JsonLdNode($entityNode);
        }

        array_push($nodes, ...$people);

        if ($image !== null) {
            $nodes[] = new JsonLdNode(['@id' => $page.NodeIds::PRIMARY_IMAGE, ...$image['object'], 'inLanguage' => $language]);
        }

        if ($trail !== null) {
            $nodes[] = $trail;
        }

        array_push($nodes, ...$this->cards->for($view, $page));

        return $nodes;
    }

    /**
     * The primary image (I-700): the featured image, else the first image
     * card at the body's top level; never the social image.
     *
     * @return array{object: array<string, mixed>, crops: list<string>}|null
     */
    private function image(ArticleView $view): ?array
    {
        $chosen = $view->fields[BuiltInFields::FEATURED_IMAGE] ?? null;
        $featured = is_array($chosen) && is_string($chosen['id'] ?? null) && $chosen['id'] !== '' ? $chosen['id'] : null;
        $id = $featured ?? $view->firstImageId;

        return $id === null ? null : $this->images->for($id);
    }

    /**
     * Each author as the article names them: a reference to their person,
     * written beside it, when they have an address; else their name alone.
     *
     * @param  list<Person>  $authors
     *
     * @return array{0: list<array<string, string>>, 1: list<JsonLdNode>}
     */
    private function authors(array $authors): array
    {
        $named = [];
        $people = [];

        foreach ($authors as $author) {
            if ($author->url === '') {
                $named[] = ['@type' => 'Person', 'name' => $author->name];

                continue;
            }

            $address = GraphAddresses::absolute($author->url);
            $named[] = ['@id' => $address.NodeIds::PERSON];
            $people[] = new JsonLdNode(['@id' => $address.NodeIds::PERSON, '@type' => 'Person', 'name' => $author->name, 'url' => $address, 'description' => $author->bio]);
        }

        return [$named, $people];
    }
}
