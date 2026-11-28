<?php

/**
 * Runs an action from a plugin's panel in the editor
 * (docs/proposals/plugin-sockets-3.md): queues the job it names as the
 * person who asked, for the item and the revision they see, once while one
 * waits. Returns the tracked job's ID.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\Editor\EditorPanels;
use App\Domain\Plugins\Editor\PanelPlugin;
use App\Domain\Plugins\Editor\PanelValidator;
use App\Domain\Plugins\Jobs\PluginJobs;
use App\Domain\Plugins\Operations\Input\RunEditorPanelActionInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Operations\OperationInput;

final class RunEditorPanelAction extends BaseOperation
{
    /** The longest unique key a queued job may carry. */
    private const int KEY_LENGTH = 64;

    public function __construct(
        private readonly EditorPanels $panels,
        private readonly PanelValidator $validator,
        private readonly StartJob $start,
        private readonly PluginRegistry $registry,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RunEditorPanelActionInput $input */
        $this->panel($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RunEditorPanelActionInput $input */
        $action = $this->panel($input)->panel->action($input->action)
            ?? throw ValidationException::withMessages(['action' => __('This panel has no such action.')]);

        $fields = [];

        foreach ($action['fields'] as $field) {
            $key = (string) ($field['key'] ?? '');
            $fields[$key] = true;
            $value = $input->values[$key] ?? null;

            if ($value === null || $value === '') {
                if (($field['required'] ?? false) === true) {
                    throw ValidationException::withMessages(["values.{$key}" => __(':field is required.', ['field' => (string) ($field['label'] ?? $key)])]);
                }

                continue;
            }

            if (! $this->validator->fits($field, $value)) {
                throw ValidationException::withMessages(["values.{$key}" => __(':field isn’t a value this action takes.', ['field' => (string) ($field['label'] ?? $key)])]);
            }
        }

        foreach (array_keys($input->values) as $key) {
            if (! isset($fields[$key])) {
                throw ValidationException::withMessages(['values' => __('This action has no field :key.', ['key' => (string) $key])]);
            }
        }
    }

    /**
     * @return array{job: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RunEditorPanelActionInput $input */
        $content = Content::query()->lockForUpdate()->findOrFail($input->contentId);

        if ($content->working_revision_id !== $input->revisionId) {
            throw StaleRevisionException::for($input->revisionId, $content->working_revision_id);
        }

        $panel = $this->panel($input);
        $action = $panel->panel->action($input->action)
            ?? throw ValidationException::withMessages(['action' => __('This panel has no such action.')]);
        $title = trim((string) ContentRevision::query()->whereKey($content->working_revision_id)->value('title'));

        $jobs = new PluginJobs($panel->name, $panel->code, $this->start, $this->registry, JobActor::person($input->actorId), notifyOnFinish: true);
        $id = $jobs->queue(
            $action['job'],
            [
                'panel' => $panel->panel->id,
                'action' => $action['id'],
                'itemId' => $content->id,
                'revisionId' => $input->revisionId,
                'values' => $this->values($action['fields'], $input->values),
            ],
            __(':action: :title', ['action' => $action['label'], 'title' => $title !== '' ? $title : __('(no title)')]),
            new QueueOptions(uniqueKey: self::uniqueKey($panel->panel->id, $action['id'], $content->id)),
        );

        return ['job' => $id];
    }

    /**
     * The key that keeps a second click from queuing the same work while
     * one waits: the panel, the action and the item, shortened to a hash of
     * the panel and action when the whole would pass the key's length.
     */
    public static function uniqueKey(string $panel, string $action, string $itemId): string
    {
        $item = strtolower($itemId);
        $key = "panel:{$panel}:{$action}:{$item}";

        return strlen($key) <= self::KEY_LENGTH ? $key : 'panel:'.substr(sha1("{$panel}:{$action}"), 0, 12).':'.$item;
    }

    /**
     * The panel named, as this person sees it on this item.
     *
     * @throws AuthorizationException when they don't see it
     */
    private function panel(RunEditorPanelActionInput $input): PanelPlugin
    {
        $content = Content::query()->find($input->contentId);

        return ($content === null ? null : $this->panels->find($content, $input->actorId, $input->code, $input->panel))
            ?? throw new AuthorizationException(__('Not permitted.'));
    }

    /**
     * The values the action's fields declare, each field's default where
     * the person sent none.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $sent
     *
     * @return array<string, mixed>
     */
    private function values(array $fields, array $sent): array
    {
        $values = [];

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $value = $sent[$key] ?? null;
            $values[$key] = $value === null || $value === '' ? ($field['default'] ?? null) : $value;
        }

        return $values;
    }
}
