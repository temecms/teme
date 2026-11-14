<?php

declare(strict_types=1);

namespace Tests\Fixtures\Contents;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use SensitiveParameter;

/**
 * Items for visibility's tests (I-1700): one published with words to look
 * for, each carrying its marker, filed under the terms given.
 */
final class Visibilities
{
    /**
     * A published item whose body, excerpt and meta description say
     * "{marker}body", "{marker}excerpt" and "{marker}description".
     *
     * @param  list<string>  $terms
     */
    public static function published(User $author, string $title, string $marker, array $terms = []): Content
    {
        $draft = app(CreateDraft::class)->execute(new CreateDraftInput(
            type: 'post',
            title: $title,
            document: cmBody("The body says {$marker}body."),
            fields: [BuiltInFields::EXCERPT => "The excerpt says {$marker}excerpt.", BuiltInFields::META_DESCRIPTION => "The description says {$marker}description."],
            actorId: (string) $author->id,
            terms: $terms,
        ));

        app(PublishRevision::class)->execute(new PublishRevisionInput($draft->id, (string) $draft->working_revision_id, (string) $author->id));

        return Content::query()->findOrFail($draft->id);
    }

    /**
     * The item after ChangeVisibility made it so, as this person.
     */
    public static function change(Content $item, string $visibility, User $by, #[SensitiveParameter] ?string $password = null): Content
    {
        app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($item->id, $visibility, $password, (string) $by->id));

        return Content::query()->findOrFail($item->id);
    }

    /**
     * The value of the cookie a reader holds after giving the item's password.
     */
    public static function cookie(Content $item): string
    {
        return (string) ReaderPasswords::key(Content::query()->findOrFail($item->id));
    }
}
