<?php

/**
 * Which other sites' browsers may call Teme's addresses, as Laravel's CORS
 * middleware reads it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

/*
 * CORS for the API's anonymous reads, llms.txt and .md pages
 * (docs/superpowers/plans/2026-11-11-phase3-slice31-delivery-api-first-release.md, D13).
 * Any origin, never credentials: the answers are public, so any page may read
 * them and none may act as anyone. With '*' and no credentials HandleCors adds
 * no Vary: Origin, which the page cache would refuse.
 */
return [

    'paths' => ['api', 'api/*', 'llms.txt', '*.md'],

    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'If-None-Match'],

    'exposed_headers' => ['ETag', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
