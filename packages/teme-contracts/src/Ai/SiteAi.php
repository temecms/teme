<?php

declare(strict_types=1);

namespace Teme\Contracts\Ai;

/**
 * Asks the site's AI connection for one of the calling plugin's declared
 * tasks, for a named person. The plugin never holds a provider key: core
 * sends the request through the model chosen for the task in Settings › AI,
 * fences the plugin's inputs, and records the request's figures under the
 * plugin, never its words.
 *
 * @since 1.0.0
 */
interface SiteAi
{
    /**
     * Whether this task can be asked now, and this plugin's AI spending this month.
     *
     * @since 1.0.0
     */
    public function standing(string $task): AiStanding;

    /**
     * Sends one request through the model chosen for this task in Settings › AI, its fallback once if
     * the first can't be reached, and records it under this plugin for $actingPersonId.
     *
     * @throws AiRefused when it may not be asked, or the model fails; the reason says which
     *
     * @since 1.0.0
     */
    public function ask(AiAsk $ask, string $actingPersonId): AiAnswer;
}
