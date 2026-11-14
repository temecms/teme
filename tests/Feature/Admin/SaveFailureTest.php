<?php

declare(strict_types=1);

it('says what each failed autosave means, and whether saving retries, stops or waits for the next change (issue 6)', function (): void {
    $answers = [
        'no answer' => [null, ''],
        '401' => [401, 'Unauthenticated.'],
        '419' => [419, 'CSRF token mismatch.'],
        '403' => [403, 'Not permitted to edit this content.'],
        '404' => [404, 'No query results for model.'],
        '413' => [413, ''],
        '500' => [500, 'Server Error'],
        '502' => [502, ''],
        '503' => [503, ''],
        '429' => [429, 'Too Many Attempts.'],
        '422' => [422, 'The title field is required.'],
        '400' => [400, 'Bad request.'],
        '409' => [409, 'Conflict.'],
    ];

    $failures = array_combine(array_keys($answers), guardNode('save-failure.mjs', ['answers' => array_values($answers)]));
    $signedOut = [
        'phrase' => 'Not saved: signed out',
        'title' => 'You’ve been signed out',
        'description' => 'Sign in again in another tab, then come back: your text is still here and saves by itself.',
        'then' => 'retry',
    ];
    $serverError = [
        'phrase' => 'Not saved: retrying',
        'title' => 'The server couldn’t save your changes',
        'description' => 'Your text is still here in the editor, and it saves as soon as the server answers.',
        'then' => 'retry',
    ];
    $refused = fn (string $reason): array => [
        'phrase' => 'Not saved',
        'title' => 'This change couldn’t be saved',
        'description' => "{$reason} Your text is still here in the editor, and saving tries again after your next change.",
        'then' => 'next-change',
    ];

    expect($failures)->toBe([
        'no answer' => ['phrase' => 'Not saved: offline', 'title' => null, 'description' => null, 'then' => 'retry'],
        '401' => $signedOut,
        '419' => $signedOut,
        '403' => [
            'phrase' => 'Not saved: no permission',
            'title' => 'You can no longer edit this item',
            'description' => 'Your text is still here in the editor. Copy it somewhere safe before you leave.',
            'then' => 'stop',
        ],
        '404' => [
            'phrase' => 'Not saved: item gone',
            'title' => 'This item is no longer here',
            'description' => 'Someone moved it to the trash or deleted it. Your text is still here in the editor. Copy it somewhere safe before you leave.',
            'then' => 'stop',
        ],
        '413' => [
            'phrase' => 'Not saved: too large',
            'title' => 'This is too large to save',
            'description' => 'Your text is still here in the editor. Shorten it, or split it into two articles, and it saves again.',
            'then' => 'next-change',
        ],
        '500' => $serverError,
        '502' => $serverError,
        '503' => $serverError,
        '429' => $serverError,
        '422' => $refused('The title field is required.'),
        '400' => $refused('Bad request.'),
        '409' => $refused('Conflict.'),
    ]);
});

it('waits after a 429 for the longer of the backoff and the Retry-After the server sent (issue 6)', function (): void {
    $now = 1_800_000_000_000;
    $delays = [
        'no header' => [4000, null],
        'seconds above the backoff' => [1000, '30'],
        'seconds below the backoff' => [8000, '2'],
        'a date ahead' => [1000, gmdate('D, d M Y H:i:s', intdiv($now, 1000) + 45).' GMT'],
        'a date past' => [2000, gmdate('D, d M Y H:i:s', intdiv($now, 1000) - 45).' GMT'],
        'nonsense' => [2000, 'soon'],
    ];

    $waits = array_combine(array_keys($delays), guardNode('save-failure.mjs', [
        'answers' => [],
        'delays' => array_map(fn (array $delay): array => [...$delay, $now], array_values($delays)),
    ])['delays']);

    expect($waits)->toBe([
        'no header' => 4000,
        'seconds above the backoff' => 30000,
        'seconds below the backoff' => 8000,
        'a date ahead' => 45000,
        'a date past' => 2000,
        'nonsense' => 2000,
    ]);
});

it('hands the Retry-After header of a refused save to the backoff (issue 6)', function (): void {
    expect(uiComponent('components/editor/editor-http.ts'))->toContain("retryAfter: headers['retry-after'] ?? null")
        ->and(uiComponent('components/editor/use-autosave.ts'))->toContain('retryDelay(backoff.current, retryAfter)');
});

it('takes a successful answer whose body isn\'t JSON, as an ended session\'s sign-in page, as signed out (issue 6)', function (): void {
    expect(uiComponent('components/editor/editor-http.ts'))
        ->toContain('return { status: 401, body: {} };');
});
