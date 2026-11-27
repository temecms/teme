<?php

/**
 * Asks the plugins listening for BeforeSave whether an item may be saved,
 * before the Operation's transaction opens. A plugin's refusal stops the
 * save and its reason is shown to the person; a plugin that fails or runs
 * over its time lets the save through, and its fault is recorded.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\ListenerFailed;
use App\Domain\Hooks\TimeBudget;
use App\Domain\Import\ImportMode;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\BeforeSave;

final readonly class BeforeSaveCheck
{
    /** Milliseconds one plugin's check may take before its answer is passed over. */
    public const int CALL_BUDGET_MS = 250;

    /** Milliseconds all the plugins' checks may take together for one save. */
    public const int PAGE_BUDGET_MS = 500;

    public function __construct(
        private HookDispatcher $dispatcher,
    ) {}

    /**
     * Throws when a plugin refuses the save, with its reason under $key;
     * returns normally otherwise. Nothing is asked for an AI suggestion, an
     * autosave or checkpoint, a plugin's own write (which names its $via),
     * or an import run, and nothing is asked when no plugin listens.
     *
     * @param  array<mixed>  $document
     * @param  array<string, mixed>  $fields
     *
     * @throws ValidationException when a plugin refuses the save
     */
    public function ask(
        ?string $itemId,
        string $type,
        ?string $language,
        string $title,
        array $document,
        array $fields,
        ?string $actorId,
        string $kind = 'save',
        ?string $via = null,
        string $key = 'title',
    ): void {
        if ($kind === 'ai' || $kind === 'checkpoint' || $via !== null) {
            return;
        }

        if (app(ImportMode::class)->active() || ! $this->dispatcher->wants(BeforeSave::class)) {
            return;
        }

        $budget = new TimeBudget(
            self::CALL_BUDGET_MS,
            self::PAGE_BUDGET_MS,
            static fn (): int => (int) hrtime(true),
            fn (string $owner, int $milliseconds, int $budget): ListenerFailed => new ListenerFailed(
                $owner.' took '.$milliseconds.' ms to check a save, over its '.$budget.' ms budget, so the save went ahead.',
            ),
        );

        $refusal = $this->dispatcher->gate(
            new BeforeSave($itemId, $type, $language, $title, $document, $fields, $actorId),
            $budget,
        );

        if ($refusal !== null) {
            throw ValidationException::withMessages([$key => $refusal->reason]);
        }
    }
}
