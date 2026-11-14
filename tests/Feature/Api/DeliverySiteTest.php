<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\DeliverySite;

uses(RefreshDatabase::class);

/**
 * An item's published revision, as stored.
 *
 * @return array{document: array<string, mixed>, fields: array<string, mixed>}
 */
function siteRevision(string $revisionId): array
{
    $row = DB::table('content_revisions')->where('id', $revisionId)->first(['document', 'fields']);

    return ['document' => json_decode((string) $row?->document, true), 'fields' => json_decode((string) $row?->fields, true)];
}

it('holds the cases the later parts must prove unreachable', function (): void {
    $site = DeliverySite::build();
    $protected = siteRevision((string) $site->protected->published_revision_id);
    $pointer = siteRevision((string) $site->pointer->published_revision_id);
    $socialUses = DB::table('media_usages')->where('media_id', $site->socialOnly->id)->get(['content_id', 'ref']);

    expect(json_encode($protected['document'], JSON_THROW_ON_ERROR))->toContain(DeliverySite::PROTECTED_WORDS)
        ->and($protected['fields'][DeliverySite::TAGLINE])->toBe(DeliverySite::PROTECTED_TAGLINE)
        ->and($site->protected->visibility->value)->toBe('protected')
        ->and($pointer['fields'][DeliverySite::SEQUEL])->toBe($site->draft->id)
        ->and($site->draft->published_revision_id)->toBeNull()
        ->and($site->ahead->published_at?->isFuture())->toBeTrue()
        ->and($site->ahead->published_revision_id)->not->toBeNull()
        ->and($site->retired->published_revision_id)->not->toBeNull()
        ->and(app(ContentTypeRegistry::class)->get('documentary')->public)->toBeFalse()
        ->and($socialUses->pluck('content_id')->unique()->values()->all())->toBe([$site->about->id])
        ->and($socialUses->pluck('ref')->unique()->values()->all())->toBe(['field:'.BuiltInFields::SOCIAL_IMAGE]);
});
