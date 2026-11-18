<?php

declare(strict_types=1);

namespace Teme\Contracts\Editor;

/**
 * A plugin whose manifest declares editor checks: core asks it about the
 * item being edited and draws what it answers beside its own checks, under
 * the plugin's name.
 *
 * @since 1.1.0
 */
interface HasEditorChecks
{
    /**
     * The results of the plugin's declared checks for this version of the
     * item, answered within 250 ms. Read only: write nothing, call no
     * service. A result for a check the manifest doesn't declare is dropped.
     *
     * @return list<CheckResult>
     *
     * @since 1.1.0
     */
    public function checkItem(CheckedItem $item): array;
}
