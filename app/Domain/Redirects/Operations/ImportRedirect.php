<?php

/**
 * Adds one redirect an import of another site needs (I-297), for the
 * importer's phases: checked as one added by hand is, marked imported, and
 * never written over a redirect already at its address, but the import's
 * own: one to a file, moved on when asked, or any, when the old site's own
 * rule outranks it. Never a person's. The run's start and end are logged,
 * never each redirect.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations;

use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Domain\Redirects\RedirectChanges;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectProposal;
use App\Domain\Redirects\RedirectRefused;
use App\Domain\Redirects\RedirectRules;
use App\Domain\Redirects\RedirectTarget;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Cache;
use Teme\Contracts\Operations\OperationInput;

final class ImportRedirect extends BaseOperation
{
    use AuthorizesImports;

    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    public const string KEPT = 'kept';

    /** The columns that say where a redirect leads: two alike in these are the same redirect. */
    private const array SAME = ['kind', 'pattern', 'status', 'target_kind', 'target', 'target_path'];

    public function __construct(private readonly RedirectRules $rules) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportRedirectInput $input */
        self::proposal($input);
    }

    /**
     * @return array{redirectId: string, outcome: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportRedirectInput $input */
        // Before anything is read, so two saves at once can't together make a chain (I-859).
        RedirectRules::lock();
        $proposal = self::proposal($input);
        $columns = $proposal->columns();
        $existing = Redirect::query()->where('source', $columns['source'])->first();

        if ($existing !== null) {
            if (self::same($existing, $columns)) {
                return ['redirectId' => (string) $existing->id, 'outcome' => self::UNCHANGED];
            }

            // The import's own rows only, never a person's: one to a file it moves on, or any when the old site's own rule outranks it.
            $moving = $existing->origin === 'import' && ($input->overImport || (
                $input->replacing !== null
                && $existing->target_kind === RedirectTarget::MEDIA
                && $existing->target === $input->replacing
            ));

            if (! $moving) {
                return ['redirectId' => (string) $existing->id, 'outcome' => self::KEPT];
            }
        }

        try {
            $this->rules->check($proposal, $existing?->id);
        } catch (RedirectRefused $refused) {
            throw RedirectChanges::refused($refused);
        }

        $actor = $input->actorId() === null ? null : (int) $input->actorId();

        if ($existing === null) {
            $redirect = Redirect::query()->create([...$columns, 'origin' => 'import', 'enabled' => true, 'created_by' => $actor, 'updated_by' => $actor]);
        } else {
            $redirect = $existing;
            $redirect->update([...$columns, 'updated_by' => $actor]);
        }

        $this->purgeAfterCommit(RedirectChanges::purged([$redirect->only(['source', 'kind'])]));

        return ['redirectId' => (string) $redirect->id, 'outcome' => $existing === null ? self::CREATED : self::UPDATED];
    }

    protected function invalidateCache(mixed $result): void
    {
        Cache::memo()->forget(RedirectLookup::PATTERNS_KEY);
    }

    /**
     * The redirect asked for, or the refusal as a form shows it.
     */
    private static function proposal(ImportRedirectInput $input): RedirectProposal
    {
        try {
            return RedirectProposal::read($input->from, $input->status, $input->to, $input->contentId, $input->mediaId, '');
        } catch (RedirectRefused $refused) {
            throw RedirectChanges::refused($refused);
        }
    }

    /**
     * @param  array{source: string, kind: string, pattern: string|null, status: int, target_kind: string|null, target: string|null, target_path: string|null, note: string}  $columns
     */
    private static function same(Redirect $redirect, array $columns): bool
    {
        foreach (self::SAME as $column) {
            if ($redirect->getAttribute($column) !== $columns[$column]) {
                return false;
            }
        }

        return true;
    }
}
