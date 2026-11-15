<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Paths;
use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\People\Models\Person;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\ListingView;

/**
 * The fediverse's alternate in a public page's head (I-058): a federated
 * item's page links its object, and an author's archive their account, so
 * a fediverse app given the page's address finds what to follow or answer.
 * None while the fediverse is off.
 */
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
     * The address of the enabled author account whose archive is at this path, or null.
     */
    private function author(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        foreach (FediverseActor::query()->where('kind', FediverseActor::PERSON)->where('enabled', true)->whereNotNull('person_id')->get() as $actor) {
            $person = Person::query()->find($actor->person_id);
            $own = $person?->user_id === null ? Paths::current('person', (string) $actor->person_id) : Paths::current('user', (string) $person->user_id);

            if ($own !== null && Addresses::toUrl($own) === $path) {
                return $actor->uri;
            }
        }

        return null;
    }
}
