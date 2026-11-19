<?php

/**
 * Asks for the inline scripts in a piece of a plugin's markup to be
 * approved, each through RequestPluginScript, as core does when a code
 * field that may hold scripts is saved; and says how many wait.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Scripts;

use App\Domain\Site\Insertions\Operations\Input\RequestPluginScriptInput;
use App\Domain\Site\Insertions\Operations\RequestPluginScript;
use Dom\HTMLDocument;

final class ScriptRequests
{
    /**
     * The text of every inline script in the markup, as a browser's parser
     * reads it, each once.
     *
     * @return list<string>
     */
    public static function inlineScripts(string $markup): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$markup, LIBXML_NOERROR, 'UTF-8');
        $scripts = [];

        foreach ($document->getElementsByTagName('script') as $element) {
            $type = strtolower(trim((string) $element->getAttribute('type')));

            if ($element->namespaceURI === 'http://www.w3.org/1999/xhtml' && ! $element->hasAttribute('src') && ($type === '' || $type === 'text/javascript') && trim((string) $element->textContent) !== '') {
                $scripts[(string) $element->textContent] = true;
            }
        }

        return array_map(strval(...), array_keys($scripts));
    }

    /**
     * Asks, for this person, that each inline script in the markup be
     * approved for the plugin, under this name; returns how many scripts of
     * the markup are not approved yet.
     */
    public static function fromMarkup(string $pluginCode, string $name, string $markup, string $actorId): int
    {
        $unapproved = 0;

        foreach (self::inlineScripts($markup) as $script) {
            $state = app(RequestPluginScript::class)->execute(new RequestPluginScriptInput($pluginCode, $name, $script, $actorId));

            if ($state !== PluginScript::APPROVED) {
                $unapproved++;
            }
        }

        return $unapproved;
    }

    /**
     * How many of this plugin's scripts wait for a decision.
     */
    public static function waiting(string $pluginCode): int
    {
        return PluginScript::query()->where('plugin_code', $pluginCode)->where('state', PluginScript::WAITING)->count();
    }
}
