<?php

declare(strict_types=1);

namespace App\Http\Admin\Ai;

use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\Asking\AiSuggestions;
use App\Domain\Ai\Asking\AppliedSuggestion;
use App\Domain\Ai\Asking\Exceptions\CannotAsk;
use App\Domain\Ai\Asking\Operations\ApplyAiSuggestion;
use App\Domain\Ai\Asking\Operations\AskForSuggestion;
use App\Domain\Ai\Asking\Operations\DiscardSuggestion;
use App\Domain\Ai\Asking\Operations\Input\ApplyAiSuggestionInput;
use App\Domain\Ai\Asking\Operations\Input\AskForSuggestionInput;
use App\Domain\Ai\Asking\Operations\Input\DiscardSuggestionInput;
use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Asking AI for a suggestion, collecting it, putting it aside and using it
 * (I-317), as JSON for the page that asked: a standalone request, so the
 * editor isn't re-rendered. It hands each request to its Operation, reads
 * the hand-off through AiSuggestions, and touches no model.
 */
final class AiRequestController extends Controller
{
    public function store(Request $request, AskForSuggestion $ask): JsonResponse
    {
        try {
            $type = AiSubjectType::tryFrom($request->string('subject_type')->toString())
                ?? throw new InvalidArgumentException('An AI request is about an item or an image.');

            $id = $ask->execute(new AskForSuggestionInput(
                $request->string('task')->toString(),
                new AiSubject($type, $request->string('subject_id')->toString(), $this->textOrNull($request, 'revision_id'), $this->textOrNull($request, 'target'), $this->options($request)),
                (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (InvalidArgumentException $invalid) {
            return response()->json(['message' => $invalid->getMessage()], 422);
        } catch (CannotAsk $refused) {
            return response()->json(['message' => $refused->getMessage()], 409);
        }

        return response()->json(['id' => $id], 202);
    }

    public function show(Request $request, string $activity, AiSuggestions $suggestions): JsonResponse
    {
        $suggestion = $suggestions->find($activity);

        // Someone else's request is as good as none.
        abort_if($suggestion === null || $suggestion->userId !== (string) $request->user()?->getAuthIdentifier(), 404);

        return response()->json($suggestion->forPage());
    }

    public function destroy(Request $request, string $activity, DiscardSuggestion $discard): Response
    {
        try {
            $discard->execute(new DiscardSuggestionInput($activity, (string) $request->user()?->getAuthIdentifier()));
        } catch (InvalidArgumentException $invalid) {
            abort(422, $invalid->getMessage());
        }

        return response()->noContent();
    }

    public function apply(Request $request, string $activity, ApplyAiSuggestion $apply): JsonResponse
    {
        // The block as the editor sent it: the global TrimStrings would cut the spaces a text node ends with.
        $body = json_decode($request->getContent(), true);
        $block = is_array($body) ? ($body['block'] ?? null) : null;
        $fields = is_array($body) && is_array($body['fields'] ?? null) ? array_values(array_filter($body['fields'], 'is_string')) : null;

        try {
            /** @var AppliedSuggestion $result */
            $result = $apply->execute(new ApplyAiSuggestionInput(
                $activity,
                (string) $request->user()?->getAuthIdentifier(),
                $this->textOrNull($request, 'revision_id'),
                $this->textOrNull($request, 'path'),
                is_array($block) ? $block : null,
                $fields,
            ));
        } catch (StaleRevisionException) {
            // As the checkpoint answers it, so the editor's own stale dialog opens.
            return response()->json(['stale' => true, 'workingRevisionId' => null, 'changedBy' => null, 'changedAt' => null], 409);
        } catch (ActivityAlreadySettled $settled) {
            return response()->json(['message' => $settled->getMessage()], 409);
        } catch (InvalidArgumentException $invalid) {
            return response()->json(['message' => $invalid->getMessage()], 422);
        }

        return $result->applied
            ? response()->json(['revisionId' => $result->revisionId])
            : response()->json(['outOfDate' => true, 'message' => $result->sentence], 409);
    }

    private function textOrNull(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The task's options, each kept as a whole number or a string.
     *
     * @return array<string, string|int>
     */
    private function options(Request $request): array
    {
        $options = [];

        foreach ((array) $request->input('options', []) as $name => $value) {
            $options[(string) $name] = is_int($value) ? $value : (is_scalar($value) ? (string) $value : '');
        }

        return $options;
    }
}
