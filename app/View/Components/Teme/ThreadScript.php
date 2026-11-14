<?php

declare(strict_types=1);

namespace App\View\Components\Teme;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::thread-script>`: core's comments script, pushed once onto the shell's stack (1.8c″'s D3). The comment form uses it, and a theme that draws a thread with no form, a closed one, places it where it draws one, so a moderator's Edit links reach it.
 */
final class ThreadScript extends Component
{
    public function render(): View
    {
        return view('teme::components.thread-script');
    }
}
