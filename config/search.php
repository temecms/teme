<?php

/**
 * Site search: how many matches a search counts before it stops.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

/*
 * Site search (slice 1.6h). `total_cap` is how many matches a search counts
 * before it stops and says "More than": a precise count of a common word over
 * a million rows is wasted work, and pages end at the cap. The language a
 * search stems by is the site language setting's (I-435), not a config value.
 */
return [
    'total_cap' => 1000,
];
