<?php

/**
 * Wires the people an item can credit (I-3366): the query class every
 * public reader of a byline asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\People\Credits;
use App\Domain\People\Queries\People;
use Illuminate\Support\ServiceProvider;

final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(People::class);
        $this->app->scoped(Credits::class);
    }
}
