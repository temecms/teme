<?php

declare(strict_types=1);

/*
 * The editor (slice 1.5c). The browser is given these numbers by the editor
 * screen, so they live in one place.
 */
return [
    /** How recently someone must have saved to count as "in the article", in seconds. */
    'presence_window' => 120,

    /**
     * How long an autosave may sit untouched before the sweep archives it
     * into the article's history as a revision (I-424). It is never cleared.
     */
    'autosave_ttl_days' => 30,

    /** Save this long after typing stops, in milliseconds. */
    'autosave_idle_ms' => 2000,

    /** While typing continues, save at least this often, in milliseconds. */
    'autosave_max_ms' => 10000,

    /** The first save after this long without one also writes a revision. */
    'checkpoint_after_minutes' => 5,

    /** How long a preview link works after it is made, in hours (I-181, 3A). */
    'preview_hours' => 48,

    /** How many due publications one run of the scheduler claims. */
    'publications_per_run' => 50,
];
