<?php

declare(strict_types=1);

namespace App\Http\Admin\Dashboard;

use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Dashboard\MainType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Quick draft (I-240, from WordPress): a title and a few lines saved as a
 * draft of the main type without opening the editor. The lines become
 * paragraphs of plain text — never parsed as HTML (rule 7). CreateDraft
 * authorizes; a refusal is a 403, as everywhere.
 */
final class QuickDraftController extends Controller
{
    public function __invoke(Request $request, CreateDraft $create): RedirectResponse
    {
        $valid = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) ($valid['body'] ?? '')) ?: [])));
        $document = ['type' => 'doc', 'content' => $paragraphs === []
            ? [['type' => 'paragraph']]
            : array_map(fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]], $paragraphs)];

        $create->execute(new CreateDraftInput(
            type: MainType::get()->key,
            title: $valid['title'],
            document: $document,
            fields: [],
            actorId: (string) $request->user()?->getAuthIdentifier(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved.')]);

        return back();
    }
}
