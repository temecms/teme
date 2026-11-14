<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Updates\Queries\UpdatesScreen;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Tools › Updates (I-3329): which Teme this site runs, the releases after it and what changed.
 * Asking for an update goes to the request and cancel controllers beside it.
 */
final class UpdatesController extends Controller
{
    public function __invoke(UpdatesScreen $screen, SettingsReader $settings): Response
    {
        return Inertia::render('tools/updates', [
            ...$screen->data(),
            'siteTimezone' => $settings->string('general', 'timezone'),
        ]);
    }
}
