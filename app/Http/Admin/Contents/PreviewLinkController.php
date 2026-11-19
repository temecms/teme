<?php

/**
 * The Share panel's endpoints: the preview links made for an article, making
 * one for its working revision, and revoking one. The Operations and the
 * query decide who may; anyone who may edit the article may share it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Operations\CreatePreviewLink;
use App\Domain\Contents\Operations\Input\CreatePreviewLinkInput;
use App\Domain\Contents\Operations\Input\RevokePreviewLinkInput;
use App\Domain\Contents\Operations\RevokePreviewLink;
use App\Domain\Contents\Queries\ContentPointers;
use App\Domain\Contents\Queries\PreviewLinkList;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PreviewLinkController extends Controller
{
    public function index(Request $request, string $content, PreviewLinkList $links): JsonResponse
    {
        $list = $links->forContent($content, $request->user());

        abort_if($list === null, 403);

        return response()->json($list);
    }

    /**
     * Makes a link to the working revision and answers with its address: the
     * only time the token is ever seen. The address is built now and never
     * stored (rule 9).
     */
    public function store(Request $request, string $content, ContentPointers $pointers, CreatePreviewLink $create): JsonResponse
    {
        $revisionId = $pointers->forIds([$content])[$content]['workingRevisionId'] ?? null;

        abort_if($revisionId === null, 404);

        $token = $create->execute(new CreatePreviewLinkInput($content, $revisionId, (string) $request->user()?->getAuthIdentifier()));

        return response()->json(['url' => url('/preview/'.$token)], 201);
    }

    public function destroy(Request $request, string $content, string $link, RevokePreviewLink $revoke): Response
    {
        $revoke->execute(new RevokePreviewLinkInput($link, (string) $request->user()?->getAuthIdentifier(), $content));

        return response()->noContent();
    }
}
