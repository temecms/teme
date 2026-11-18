<?php

/**
 * What a plugin granted import.wordpress implements to receive the data it
 * declared under its manifest's `imports`, as an import of a WordPress site
 * reads it: its options once a run, then each record of its post types, of
 * people's meta and of terms' meta, one at a time.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * What a plugin granted import.wordpress implements to receive the data it
 * declared under its manifest's `imports`, as an import of a WordPress site
 * reads it: its options once a run, then each record of its post types, of
 * people's meta and of terms' meta, one at a time. Teme reads only what the
 * plugin declared, never a password or a session, and never sends PHP's
 * serialized values: a value WordPress kept serialized arrives decoded, and
 * one holding a PHP object arrives as null.
 *
 * @since 1.0.0
 */
interface WordPressImporter
{
    /**
     * Called once per run, before any record, with the declared options'
     * values by name: decoded JSON values, null for one that held a PHP
     * object or that WordPress doesn't have. Whatever it throws is reported,
     * and the run goes on.
     *
     * @param  array<string, mixed>  $options
     *
     * @since 1.0.0
     */
    public function options(array $options, ImportScope $scope): void;

    /**
     * One record: a post of a declared type, a person's declared meta, or a
     * term's. Called inside the record's own transaction, so what it writes
     * is undone when it fails or throws. A record unchanged since the last
     * run isn't handed over again.
     *
     * @since 1.0.0
     */
    public function record(WordPressRecord $record, ImportScope $scope): ImportOutcome;
}
