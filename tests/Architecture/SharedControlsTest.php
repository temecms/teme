<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

// uiComponent() lives in tests/Pest.php.

it('sets the control heights the design system asks for, from the tokens', function (): void {
    $button = uiComponent('components/ui/button.tsx');

    expect($button)->toContain('h-(--ctl-h)')
        ->and($button)->toContain('h-(--ctl-h-sm)')
        ->and($button)->toContain('h-(--ctl-h-xs)')
        ->and(uiComponent('components/ui/input.tsx'))->toContain('h-(--ctl-h)')
        ->and(uiComponent('components/ui/select.tsx'))->toContain('h-(--ctl-h)');
});

it('draws the focus ring at full strength', function (string $path): void {
    expect(uiComponent($path))->not->toContain('ring-ring/50')
        ->and(uiComponent($path))->not->toContain('ring-[3px]');
})->with(['components/ui/button.tsx', 'components/ui/input.tsx', 'components/ui/select.tsx']);

it('gives no page its own select styling', function (string $page): void {
    expect(uiComponent($page))->not->toContain('selectClass')
        ->and(uiComponent($page))->not->toContain('<select')
        ->and(uiComponent($page))->toContain("from '@/components/ui/select-field'");
})->with(['pages/content/index.tsx', 'components/people/invite-dialog.tsx', 'components/people/role-dialog.tsx', 'components/people/delete-dialog.tsx', 'components/list/screen-options.tsx', 'components/list/bulk-edit-dialog.tsx', 'components/themes/design-field.tsx']);

it('keeps the empty choice\'s sentinel inside the select field', function (): void {
    $field = uiComponent('components/ui/select-field.tsx');

    expect($field)->toContain("'__none__'")
        ->and($field)->toContain('emptyLabel')
        ->and($field)->toContain('type="hidden"');

    foreach (uiTsxFiles() as $file) {
        if ($file !== 'components/ui/select-field.tsx') {
            expect(uiComponent($file))->not->toContain('__none__');
        }
    }
});

it('offers the two empty choices through the select field', function (string $page, string $label): void {
    // Written plain, or through __(), which Prettier may wrap.
    expect((string) preg_replace('/\s+/', ' ', uiComponent($page)))->toMatch('/emptyLabel=(?:"'.preg_quote($label, '/')."\"|\\{__\\( ?'".preg_quote($label, '/')."',? ?\\)\\})/");
})->with([
    'reassigning a deleted person\'s content' => ['components/people/delete-dialog.tsx', 'No one — they wrote nothing'],
]);

it('rounds a badge fully and fills no status with a solid colour', function (): void {
    $badge = uiComponent('components/ui/badge.tsx');

    expect($badge)->toContain('rounded-full')
        ->and($badge)->not->toContain('rounded-sm')
        ->and($badge)->toContain('bg-ok-soft text-ok-ink')
        ->and($badge)->toContain('bg-danger-soft text-danger-ink');
});

it('lifts a panel on the soft shadow, with no border', function (): void {
    $panel = uiComponent('components/ui/panel.tsx');

    expect($panel)->toContain('shadow-raised')
        ->and($panel)->toContain('rounded-lg')
        ->and($panel)->toContain('bg-surface')
        ->and($panel)->not->toContain('border-t');
});

it('gives the stronger shadows to things that float', function (string $path, string $shadow): void {
    expect(uiComponent($path))->toContain($shadow);
})->with([
    ['components/ui/dialog.tsx', 'shadow-modal'],
    ['components/ui/sheet.tsx', 'shadow-modal'],
    ['components/ui/dropdown-menu.tsx', 'shadow-float'],
    ['components/ui/popover.tsx', 'shadow-float'],
]);

it('draws the card as a panel', function (): void {
    $card = uiComponent('components/ui/card.tsx');

    expect($card)->toContain('shadow-raised')
        ->and($card)->toContain('rounded-lg')
        ->and($card)->not->toContain('shadow-sm')
        ->and($card)->not->toContain('rounded-xl');
});

it('marks the current tab with the foreground and a 2px accent underline', function (): void {
    $tabs = uiComponent('components/list/tab-strip.tsx');

    expect($tabs)->toContain('border-b-2')
        ->and($tabs)->toContain('border-accent text-ink')
        ->and($tabs)->toContain('h-9')
        ->and($tabs)->not->toContain('text-accent');
});

it('draws 44px rows separated by hairlines in one bordered region', function (): void {
    $table = uiComponent('components/list/data-table.tsx');

    // 12px cells above and below a 20px line make the 44px row.
    expect($table)->toContain('h-11')
        ->and($table)->toContain('py-3')
        ->and($table)->toContain('leading-5')
        ->and($table)->toContain('border-b')
        ->and(substr_count($table, '<Card'))->toBe(1);
});

it('gives the bulk bar a muted background and a hairline, not the accent', function (): void {
    $bar = uiComponent('components/list/bulk-bar.tsx');

    expect($bar)->toContain('bg-surface-2')
        ->and($bar)->toContain('border-edge')
        ->and($bar)->not->toContain('bg-accent');
});
