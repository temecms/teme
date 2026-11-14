<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Input\ImportOldRedirectInput;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Redirects\RedirectChains;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Imports one redirect rule of the old site (I-297): its redirects through
 * ImportRedirect, sent straight on and over the import's own but never a
 * person's, each one a person changed or deleted here since left as it is;
 * then its map row and messages, in one transaction. A rule whose hash
 * hasn't changed writes nothing.
 */
final class ImportOldRedirect extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;

    /** A comment page's address: the comments phase's redirect there leads where the comments are now (I-610). */
    private const string COMMENT_PAGE = '#/comment-page-\d+/?$#';

    public function __construct(private readonly ImportMap $map, private readonly RedirectChains $chains) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportOldRedirectInput $input */
        if ($input->ref->type !== 'redirect' || ($input->redirects === [] && $input->notes === [])) {
            throw new InvalidArgumentException('A rule needs its redirect object, and a redirect or a note saying why it has none.');
        }
    }

    protected function perform(OperationInput $input): ?ImportOutcome
    {
        /** @var ImportOldRedirectInput $input */
        $run = ImportRun::query()->whereKey($input->runId)->sharedLock()->first();

        if ($run === null || $run->state !== ImportRunState::Running) {
            return null;
        }

        $this->lockObject($input->sourceId, $input->ref);
        $entry = $this->map->entry($input->sourceId, $input->ref);

        if ($entry !== null && $entry->source_hash === $input->hash) {
            return null;
        }

        $facts = $entry === null ? [] : $entry->facts;
        /** @var array<string, string> $before */
        $before = is_array($facts['redirects'] ?? null) ? $facts['redirects'] : [];
        $messages = $input->notes;
        $ids = [];
        $live = [];
        $created = false;

        foreach ($input->redirects as $redirect) {
            $from = $redirect['from'];
            $context = ['from' => mb_substr($from, 0, 500)];
            $known = $before[$from] ?? null;
            // Cut short in words: a message holds at most 1,000 characters.
            $said = Str::limit($from, 300);

            if ($known !== null && $entry !== null) {
                $changed = DB::table('redirects')->where('id', $known)->value('updated_at');

                // Deleted here: it stays so, and stays recorded, so no later run brings it back.
                if ($changed === null) {
                    $ids[$from] = $known;
                    $messages[] = new ImportMessage('redirect.deleted-here', "Its redirect from {$said} was deleted here since the last import, so it wasn’t brought back.", $context);

                    continue;
                }

                if (CarbonImmutable::parse((string) $changed)->gt($entry->imported_at)) {
                    $ids[$from] = $known;
                    $live[] = $known;
                    $messages[] = new ImportMessage('redirect.kept-here', "Its redirect from {$said} was changed here since the last import, so it was kept as it is.", $context);

                    continue;
                }
            }

            $written = $this->write($redirect, $input->actorId());

            if (is_string($written)) {
                $reason = Str::limit($written, 600);
                $messages[] = new ImportMessage('redirect.refused', "Teme couldn’t add its redirect from {$said}: {$reason}", $context);
            } elseif ($written['outcome'] === ImportRedirect::KEPT) {
                $messages[] = new ImportMessage('redirect.kept', "Teme already has a redirect from {$said}, so that one was kept as it is.", $context);
            } else {
                $ids[$from] = $written['redirectId'];
                $live[] = $written['redirectId'];
                $created = $created || $written['outcome'] === ImportRedirect::CREATED;
            }
        }

        $outcome = match (true) {
            $created => ImportOutcome::Created,
            $live !== [] => ImportOutcome::Updated,
            default => ImportOutcome::Skipped,
        };
        $now = now();

        $entry ??= new ImportMapEntry(['source_id' => $input->sourceId, 'object_type' => $input->ref->type, 'object_id' => $input->ref->id]);
        $entry->fill([
            // A skip keeps what an earlier run made of the rule.
            'teme_type' => $live === [] ? $entry->teme_type : 'redirect',
            'teme_id' => $live === [] ? $entry->teme_id : $live[0],
            'status' => $outcome->status(),
            'outcome' => $outcome,
            'source_hash' => $input->hash,
            'facts' => [...$facts, 'redirects' => $ids],
            'run_id' => $run->id,
            'imported_at' => $now,
        ])->save();

        ImportMessageRecord::query()->where('map_id', $entry->id)->delete();

        foreach ($messages as $message) {
            ImportMessageRecord::query()->create([
                'run_id' => $run->id, 'map_id' => $entry->id, 'level' => $message->level, 'code' => $message->code,
                'message' => $message->message, 'context' => $message->context, 'created_at' => $now,
            ]);
        }

        return $outcome;
    }

    /**
     * One redirect sent straight on and written (I-859): ImportRedirect's
     * answer, or why it couldn't be, in words.
     *
     * @param  array{from: string, to: string, contentId: string|null, mediaId: string|null, status: int}  $redirect
     *
     * @return array{redirectId: string, outcome: string}|string
     */
    private function write(array $redirect, ?string $actorId): array|string
    {
        $forward = $this->chains->forward($redirect);

        if (is_string($forward)) {
            return $forward;
        }

        try {
            $this->chains->repoint($forward, $actorId);

            return app(ImportRedirect::class)->execute(new ImportRedirectInput(
                from: $forward['from'],
                to: $forward['to'],
                contentId: $forward['contentId'],
                mediaId: $forward['mediaId'],
                status: $forward['status'],
                actorId: $actorId,
                overImport: preg_match(self::COMMENT_PAGE, $forward['from']) !== 1,
            ));
        } catch (ValidationException $refused) {
            return (string) (Arr::first(Arr::flatten($refused->errors())) ?? '');
        }
    }
}
