<?php

/**
 * A plugin's answer for a public page's title (docs/guides/developers/plugins/seo.md):
 * the first enabled plugin granted pages.head that gives one, asked within
 * 100 ms, replaces core's title, and so the social title and the
 * structured data's name that follow it. A title that is empty or longer
 * than 200 characters is refused, recorded as the plugin's fault, and core's
 * is kept. An item's own social title still wins over it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Cache\RenderRecord;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Head\HeadValues;
use App\Domain\Site\ViewModels\ArticleView;
use Teme\Contracts\Head\HasPageTitle;
use Teme\Contracts\Plugins\Capability;

final readonly class PluginPageTitle
{
    public function __construct(
        private SeoPlugins $plugins,
        private HeadPages $pages,
        private RenderRecord $record,
    ) {}

    /**
     * Core's head values, with the title a plugin gave in its place, or as they were.
     */
    public function apply(DocumentPage $page, HeadValues $values): HeadValues
    {
        if ($page->intent === null || ! isset(HeadPages::KINDS[$page->intent->kind])) {
            return $values;
        }

        $asked = array_values(array_filter($this->plugins->all(), fn (SeoPlugin $plugin): bool => $plugin->grants(Capability::PagesHead->value) && $plugin->running instanceof HasPageTitle));

        if ($asked === []) {
            return $values;
        }

        $facts = $this->pages->for($page, $values);

        if ($facts === null) {
            return $values;
        }

        foreach ($asked as $plugin) {
            $running = $plugin->running;

            if (! $running instanceof HasPageTitle) {
                continue;
            }

            // Its settings and its own being enabled change the title it gives.
            $this->record->tag('plugin:'.$plugin->code);
            $seen = $facts($plugin);
            $given = $this->plugins->ask($plugin, 'head-title', HasPageTitle::class, PluginHead::CALL_BUDGET_MS, fn (): ?string => $running->pageTitle($seen, $values->title));

            if ($given === null) {
                continue;
            }

            // The first plugin that answers is the one: a refused answer is still its answer, and core's title stays.
            $title = is_string($given) ? trim($given) : '';

            if ($title === '' || mb_strlen($title) > HasPageTitle::MOST_CHARACTERS) {
                $this->plugins->fault($plugin, 'head-title', HasPageTitle::class, new SocketAnswerRefused('A page title is 1 to '.HasPageTitle::MOST_CHARACTERS.' characters of text.'));

                return $values;
            }

            return $this->with($page, $values, $title);
        }

        return $values;
    }

    /**
     * The values with this title, and the social title following it unless the item set its own.
     */
    private function with(DocumentPage $page, HeadValues $values, string $title): HeadValues
    {
        $model = $page->model;
        $own = $model instanceof ArticleView && is_string($model->fields[BuiltInFields::SOCIAL_TITLE] ?? null) && trim($model->fields[BuiltInFields::SOCIAL_TITLE]) !== '';

        return new HeadValues(
            $title,
            $values->description,
            $values->url,
            $values->canonical,
            $values->robots,
            $values->socialTitle === null || $own ? $values->socialTitle : $title,
            $values->socialDescription,
            $values->socialType,
            $values->image,
            $values->prev,
            $values->next,
        );
    }
}
