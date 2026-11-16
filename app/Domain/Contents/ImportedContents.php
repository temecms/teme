<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Paths;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldValidator;
use App\Domain\Taxonomy\TermRules;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What a WordPress post or page the import writes must meet (I-332): Teme's
 * own rules for its type, title, slug, status, author, terms, fields,
 * template, document and address; the fingerprint a later run compares to
 * know it is as the import left it; and the address it would have here.
 */
final class ImportedContents
{
    /** The statuses an imported item may have. */
    public const array STATUSES = ['draft', 'review', 'scheduled', 'published'];

    /** The most old addresses one item keeps: a plugin that loops can leave hundreds of old slugs. */
    public const int MOST_OLD_ADDRESSES = 50;

    /** Teme's slug rule, as UpdateContentDetails holds it (I-397). */
    private const string SLUG = '/^[\p{Ll}\p{Lo}\p{N}]+(?:-[\p{Ll}\p{Lo}\p{N}]+)*$/u';

    /**
     * Refuses an item Teme's own rules wouldn't save: its type, title, slug,
     * status and date, author, terms, fields, template, document and address.
     *
     * @param  array<string, mixed>  $storedFields  the fields of the revision it replaces; [] for a new item
     *
     * @throws ValidationException
     */
    public static function validate(string $contentId, ImportedItem $item, array $storedFields): void
    {
        $types = app(ContentTypeRegistry::class);

        if (! $types->has($item->type) || ! $types->get($item->type)->listed) {
            throw ValidationException::withMessages(['type' => 'Teme has no such content type to import into.']);
        }

        if (trim($item->title) === '' || mb_strlen($item->title) > 512) {
            throw ValidationException::withMessages(['title' => 'Give it a title of 512 characters or fewer.']);
        }

        if (mb_strlen($item->slug) > 200 || preg_match(self::SLUG, $item->slug) !== 1) {
            throw ValidationException::withMessages(['slug' => 'Its address name must be letters and numbers joined by hyphens, 200 characters at most.']);
        }

        // In the trash, an item keeps only a published status or a draft's, as TrashContent leaves it.
        if (! in_array($item->status, self::STATUSES, true) || ($item->trashedAt !== null && in_array($item->status, ['review', 'scheduled'], true))) {
            throw ValidationException::withMessages(['status' => 'That status can’t be imported.']);
        }

        if ($item->visibility === Visibility::Protected) {
            $problem = ReaderPasswords::problem($item->password ?? '');

            if ($problem !== null) {
                throw ValidationException::withMessages(['password' => $problem]);
            }
        } elseif ($item->password !== null) {
            throw ValidationException::withMessages(['password' => 'Only an item protected by a password keeps one.']);
        }

        if (in_array($item->status, ['scheduled', 'published'], true) && $item->publishedAt === null) {
            throw ValidationException::withMessages(['published_at' => 'A published or scheduled item needs its date.']);
        }

        if (! User::query()->whereKey($item->authorId)->exists()) {
            throw ValidationException::withMessages(['author' => 'The person it belongs to isn’t here.']);
        }

        // The trash frees a slug (I-142); only a live item needs its own.
        if ($item->trashedAt === null && ! Slugs::isAvailable($item->type, $item->slug, $contentId, $item->language)) {
            throw ValidationException::withMessages(['slug' => __('Another :type already uses this address.', ['type' => $item->type])]);
        }

        Validator::make(['terms' => $item->terms], [
            'terms' => ['array', 'max:50'],
            'terms.*' => ['string', 'distinct', TermRules::usableBy($item->type, $item->language)],
        ])->validate();

        app(FieldValidator::class)->validate($item->type, $item->fields);
        TemplateChoice::check($item->type, $item->fields, $storedFields);
        app(DocumentValidator::class)->validate(DocumentNormaliser::normalise($item->document));

        $address = self::address($contentId, $item);
        $clash = $address === null ? null : Paths::conflict($address, 'content', $contentId, $item->language);

        if ($clash !== null) {
            throw ValidationException::withMessages(['slug' => $clash]);
        }
    }

    /**
     * The item's state as a fingerprint: its slug, status, author,
     * discussion, published, scheduled and trashed times, and its working and
     * published revisions. Any save in Teme moves it; an autosave doesn't,
     * nor does Teme's scheduler publishing the revision the import scheduled,
     * which is hashed as the scheduled item it was.
     */
    public static function fingerprint(Content $content): string
    {
        $status = (string) $content->status;
        $scheduledFor = $content->scheduled_for;
        $published = (string) $content->published_revision_id;

        // PublishRevision sets the status and the published revision and clears scheduled_for: the import's own plan, carried out.
        if ($status === 'published' && $published !== '' && $published === (string) $content->working_revision_id
            && ScheduledPublication::query()->where('content_id', $content->id)->where('revision_id', $published)->whereNotNull('completed_at')->exists()) {
            $status = 'scheduled';
            $scheduledFor = $content->published_at;
            $published = '';
        }

        $parts = [
            (string) $content->slug,
            $status,
            (string) $content->author_id,
            $content->comment_status->value,
            self::instant($content->published_at),
            self::instant($scheduledFor),
            self::instant($content->deleted_at),
            (string) $content->working_revision_id,
            $published,
        ];

        // Only an item readers can't all read adds who may (I-1700), so every
        // fingerprint an import wrote before stays as it was.
        if ($content->visibility !== Visibility::Public) {
            $parts[] = $content->visibility->value;
            $parts[] = ReaderPasswords::fingerprint($content);
        }

        return sha1(json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * The address the item would have here with its details, or null for one
     * that isn't published, is private or is in the trash.
     */
    public static function address(string $contentId, ImportedItem $item): ?string
    {
        if ($item->status !== 'published' || $item->trashedAt !== null || $item->visibility === Visibility::Private) {
            return null;
        }

        $content = new Content(['type' => $item->type, 'slug' => $item->slug]);
        $content->id = $contentId;
        // Another language's address carries its prefix (I-3360).
        $content->language = $item->language;

        return Addresses::wouldHave($content, $item->slug, $item->terms, Addresses::ownerSlug($content, (int) $item->authorId), $item->publishedAt, $item->fields[BuiltInFields::PRIMARY_CATEGORY] ?? null);
    }

    /**
     * A moment as UTC to the second, or null.
     */
    private static function instant(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $moment = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);

        return $moment->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
