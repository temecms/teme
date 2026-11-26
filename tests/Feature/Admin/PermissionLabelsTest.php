<?php

/**
 * Every core permission has its own plain-words label.
 *
 * @package Teme
 * @license MIT
 */

declare(strict_types=1);

use App\Http\Admin\PermissionLabels;
use Database\Seeders\RoleSeeder;

it('gives every core permission a label that is not its dotted name', function (): void {
    foreach (RoleSeeder::PERMISSIONS as $permission) {
        $label = PermissionLabels::of($permission);

        expect($label)->not->toBe($permission, "{$permission} has no label")
            ->and($label)->not->toContain('.');
    }
});

it('says a plugin permission as written', function (): void {
    expect(PermissionLabels::of('seo.audit'))->toBe('seo.audit');
});
