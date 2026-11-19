<?php

/**
 * Asked once each time an article is drawn, on the site, in a feed or by the
 * API, with the links it draws: a listener returns, by each link's place in
 * $links, the LinkAttributes Teme writes on it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Transform;

/**
 * Asked once each time an article is drawn, on the site, in a feed or by
 * the API, with the links it draws: a listener returns, by each link's
 * place in $links, the LinkAttributes Teme writes on it. A value outside
 * LinkAttributes' rules fails that listener, which is recorded and skipped.
 *
 * @since 1.0.0
 */
#[HookContract(capability: 'content.links')]
final readonly class ArticleLinks implements Transform
{
    /**
     * @param  list<OutboundLink>  $links  each link the article draws, once each
     * @param  array<int, LinkAttributes>  $attributes  by place in $links, as earlier listeners left them
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $links,
        public array $attributes = [],
    ) {}

    /** @since 1.0.0 */
    public static function valueType(): string
    {
        return 'array';
    }

    /** @since 1.0.0 */
    public function value(): mixed
    {
        return $this->attributes;
    }
}
