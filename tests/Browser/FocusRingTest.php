<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/_catalogue')->assertOk();
});

/**
 * The focused control's outline and edge, once the keyboard has reached it:
 * whether it is the focus, the ring's style, width and colour, the colour
 * --ring resolves to, and its edge's colour.
 *
 * @return array{focused: bool, style: string, width: string, color: string, ring: string, edge: string}
 */
function frFocused(mixed $page, string $selector): array
{
    $page->keys($selector, 'Shift');

    return cmScriptArray($page, <<<JS
        () => {
            const control = document.querySelector('{$selector}');
            const style = getComputedStyle(control);
            const probe = document.createElement('div');
            probe.style.color = 'var(--ring)';
            document.body.append(probe);
            const ring = getComputedStyle(probe).color;
            probe.remove();

            return {
                focused: document.activeElement === control && control.matches(':focus-visible'),
                style: style.outlineStyle,
                width: style.outlineWidth,
                color: style.outlineColor,
                ring,
                edge: style.borderTopColor,
            };
        }
        JS, 'frFocused');
}

it('rings a focused text field, text area, select and combobox in the focus colour, in place of their edge (#431)', function (string $selector): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue"]\') !== null');

    $focused = frFocused($page, $selector);

    expect($focused['focused'])->toBeTrue()
        ->and($focused['style'])->toBe('solid')
        ->and($focused['width'])->toBe('2px')
        ->and($focused['color'])->toBe($focused['ring'])
        ->and($focused['edge'])->toBe('rgba(0, 0, 0, 0)');
})->with([
    'a text field' => '#catalogue-input-rest',
    'a text area' => '#catalogue-textarea-rest',
    'a select' => '#catalogue-select-rest',
    'a combobox' => '#catalogue-combobox-single',
]);

it('rings the sign-in form\'s email field when it has the focus (#431)', function (): void {
    $page = visit('/admin/login');
    cmWaitUntil($page, 'document.querySelector(\'input[name="email"]\') !== null');

    $focused = frFocused($page, 'input[name="email"]');

    expect($focused['focused'])->toBeTrue()
        ->and($focused['style'])->toBe('solid')
        ->and($focused['width'])->toBe('2px')
        ->and($focused['color'])->toBe($focused['ring']);
});
