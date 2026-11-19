<?php

/**
 * The fediverse's alternate in a public page's head (I-058): a federated
 * item's page links its object, and an author's archive their account, so
 * a fediverse app given the page's address finds what to follow or answer.
 * None while the fediverse is off.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\ListingView;
use Illuminate\Database\Query\JoinClause;

final readonly class AlternateLinks implements HeadContributor
{
    public function __construct(private PublishingSettings $settings) {}

    public function precedence(): Precedence
    {
        return Precedence::Core;
    }

    public function entries(DocumentPage $page): array
    {
        if (! FediverseSwitch::on()) {
            return [];
        }

        $uri = match (true) {
            $page->model instanceof ArticleView => $this->object($page->model),
            $page->model instanceof ListingView && $page->intent?->kind === 'author' && $page->model->subject?->kind === 'author' => $this->author((string) $page->model->subject->url),
            default => null,
        };

        return $uri === null ? [] : [new LinkTag(LinkRelation::Alternate, $uri, ActivityPub::CONTENT_TYPE)];
    }

    /**
     * The address of a federated item's object, or null.
     */
    private function object(ArticleView $item): ?string
    {
        if (! $this->settings->federates($item->type)) {
            return null;
        }

        $uri = FediverseObject::query()->where('content_id', $item->id)->where('kind', FediverseObject::POST)->whereNull('deleted_at')->value('uri');

        return is_string($uri) ? $uri : null;
    }

    /**
     * The address of the enabled author account whose archive is at this
     * path, or null; none while only the blog can be followed. One query:
     * the account's person, and the current address of the person's
     * account, or of the person when they have none.
     */
    private function author(string $path): ?string
    {
        $path = trim($path, '/');

        if ($path === '' || ! FediverseSettingsGroup::authorsFollowable()) {
            return null;
        }

        $uri = FediverseActor::query()
            ->join('people', 'people.id', '=', 'fediverse_actors.person_id')
            ->join('paths', function (JoinClause $join): void {
                $join->where('paths.is_current', true)->where(function (JoinClause $owner): void {
                    $owner->where(function (JoinClause $account): void {
                        $account->whereNotNull('people.user_id')->where('paths.target_type', 'user')->whereRaw('paths.target_id = people.user_id::text');
                    })->orWhere(function (JoinClause $person): void {
                        $person->whereNull('people.user_id')->where('paths.target_type', 'person')->whereColumn('paths.target_id', 'people.id');
                    });
                });
            })
            ->where('fediverse_actors.kind', FediverseActor::PERSON)
            ->where('fediverse_actors.enabled', true)
            ->where('paths.path', $path)
            ->value('fediverse_actors.uri');

        return is_string($uri) ? $uri : null;
    }
}
