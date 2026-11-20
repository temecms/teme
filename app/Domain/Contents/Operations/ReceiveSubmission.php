<?php

/**
 * Keeps what a visitor sent from a plugin's public form as an item waiting
 * for review, written as the site itself: no author, marked as a submission,
 * with a pending review no person submitted. It never publishes.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Operations\Input\ReceiveSubmissionInput;
use App\Domain\Contents\Slugs;
use App\Domain\Fields\FieldValidator;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermRules;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Plugins\Capability;

/**
 * Keeps what a visitor sent from a plugin's public form as an item waiting
 * for review, written as the site itself rather than a person. Only an
 * enabled plugin granted content.submissions may hand one over.
 */
final class ReceiveSubmission extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var ReceiveSubmissionInput $input */
        $record = PluginRecord::query()->where('code', $input->plugin)->first();

        if ($record === null
            || $record->state !== PluginRecord::ENABLED
            || ! in_array(Capability::ContentSubmissions->value, $record->consent['capabilities'] ?? [], true)) {
            throw new AuthorizationException(__('This plugin may not keep submissions.'));
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReceiveSubmissionInput $input */
        Validator::make(
            [
                'type' => $input->type,
                'title' => trim($input->title),
                'document' => $input->document,
                'terms' => $input->terms,
                'from' => $input->from,
            ],
            [
                'type' => ['required', 'string', Rule::in(app(ContentTypeRegistry::class)->keys())],
                'title' => ['string', 'max:512'],
                'document' => ['required', 'array'],
                'terms' => ['array', 'max:50'],
                'terms.*' => ['string', 'distinct', TermRules::usableBy($input->type, null)],
                'from' => ['nullable', 'string', 'max:255'],
            ],
        )->validate();

        $type = app(ContentTypeRegistry::class)->get($input->type);

        if (! $type->listed) {
            throw ValidationException::withMessages(['type' => __(':plural are made on their own screen, not as new drafts.', ['plural' => $type->plural])]);
        }

        app(FieldValidator::class)->validate($input->type, $input->fields);
        TemplateChoice::check($input->type, $input->fields, []);
        app(DocumentValidator::class)->validate(DocumentNormaliser::normalise($input->document));
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var ReceiveSubmissionInput $input */
        CreateDraft::refuseSecond($input->type, null);

        $title = trim($input->title);
        $content = CreateDraft::start(
            $input->type,
            Slugs::unique($input->type, Slugs::fromTitle($title), null),
            $title,
            $input->document,
            app(FieldValidator::class)->validate($input->type, $input->fields),
            $input->terms,
            null,
            via: $input->plugin,
        );

        $before = ContentState::of($content);
        $from = $input->from === null ? null : trim($input->from);
        $content->update([
            'status' => 'review',
            'submitted_via' => $input->plugin,
            'submitted_from' => $from === '' ? null : $from,
        ]);
        ContentCounts::apply($before, ContentState::of($content));

        ContentReview::query()->create([
            'content_id' => $content->id,
            'revision_id' => $content->working_revision_id,
            'submitted_by' => null,
            'submitted_at' => Date::now(),
        ]);

        return $content->refresh();
    }
}
