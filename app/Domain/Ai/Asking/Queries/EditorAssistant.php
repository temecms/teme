<?php

/**
 * What the editor's assistant pane offers one person (I-321): the tasks
 * about an item they may ask for now, where those tasks' text may be sent
 * (I-1304), whether they may change Settings › AI, and the hourly limit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Queries;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\UsageLimits;
use App\Models\User;

final readonly class EditorAssistant
{
    public function __construct(
        private AiAvailability $availability,
        private AiTasks $tasks,
        private ModelChoices $choices,
        private AiProviders $providers,
    ) {}

    /**
     * The pane's offer, or null when there is no task to offer: then the
     * editor shows no assistant at all.
     *
     * @return array{tasks: list<array{key: string, label: string}>, sentTo: list<string>, canManage: bool, hourlyLimit: int}|null
     */
    public function for(?User $user): ?array
    {
        $tasks = [];
        $sentTo = [];

        foreach ($this->availability->tasksFor($user) as $key) {
            $task = $this->tasks->get($key);

            if ($task->subject() !== AiSubjectType::Content) {
                continue;
            }

            $tasks[] = ['key' => $key, 'label' => $task->label()];
            $choice = $this->choices->for($key);

            // The fallback's provider too: the text may go there.
            foreach ([$choice?->primary, $choice?->fallback] as $ref) {
                $label = $ref === null ? null : $this->providers->find($ref->provider)?->label();

                if ($label !== null && ! in_array($label, $sentTo, true)) {
                    $sentTo[] = $label;
                }
            }
        }

        if ($tasks === [] || $user === null) {
            return null;
        }

        return [
            'tasks' => $tasks,
            'sentTo' => $sentTo,
            'canManage' => $user->can(AiSettings::ABILITY),
            'hourlyLimit' => UsageLimits::hourly(),
        ];
    }
}
