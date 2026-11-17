<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\RelayPro;

use RuntimeException;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Tests\Fixtures\Plugins\Relay\Hooks\ArticleTitle;
use Tests\Fixtures\Plugins\Relay\Hooks\ArticleWritten;
use Tests\Fixtures\Plugins\Relay\Hooks\PromptModules;

/**
 * Relay's add-on: hears each of Relay's hooks. Its first prompt-modules
 * listener fails for the topic "fail", so the tests see one fault and the
 * next listener still heard.
 */
final class RelayProPlugin implements Plugin
{
    /** @var list<string> the articles it heard were written */
    public array $written = [];

    public function register(Hooks $hooks): void
    {
        $hooks->on(PromptModules::class, function (PromptModules $hook): array {
            if ($hook->topic === 'fail') {
                throw new RuntimeException('Relay Pro couldn’t build its modules.');
            }

            return ['pro:tone'];
        });
        $hooks->on(PromptModules::class, fn (PromptModules $hook): array => ['pro:'.$hook->topic]);
        $hooks->on(ArticleTitle::class, fn (string $title, ArticleTitle $hook): string => $title.' (Pro)');
        $hooks->on(ArticleWritten::class, function (ArticleWritten $hook): void {
            $this->written[] = $hook->title;
        });
    }
}
