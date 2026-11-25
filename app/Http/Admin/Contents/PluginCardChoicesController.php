<?php

/**
 * The options a plugin's card offers for one of its choice fields, asked by
 * the editor while someone who may manage regions fills the card in.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Cards\Plugin\Queries\PluginCardChoices;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The options a plugin's card offers for one of its choice fields
 * (docs/guides/developers/plugins/cards.md): a read, for people who may
 * manage regions; 404 for an item that isn't a region or a card no running
 * plugin offers.
 */
final class PluginCardChoicesController extends Controller
{
    public function __invoke(Request $request, string $content, string $card, string $field, PluginCardChoices $choices): JsonResponse
    {
        $options = $choices->for($content, $card, $field, (string) $request->query('q', ''));

        abort_if($options === null, 404);

        return response()->json(['choices' => $options]);
    }
}
