<?php

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * One list's rows on a plugin's settings page, newest or most useful first.
 *
 * @since 1.0.0
 */
interface PluginList
{
    /**
     * At most $limit rows; each row its cells by declared column id. A missing cell shows empty.
     *
     * @return list<array<string, Cell>>
     *
     * @since 1.0.0
     */
    public function rows(int $limit): array;
}
