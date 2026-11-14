<?php

declare(strict_types=1);

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\CoreCommentStatuses;

it('holds core\'s five statuses, and which a moderator may choose, readers see and one deletes from (I-224)', function (): void {
    $registry = new CommentStatusRegistry;
    CoreCommentStatuses::register($registry);

    expect(array_map(fn (CommentStatus $status): string => $status->key, $registry->all()))->toBe(['pending', 'approved', 'spam', 'trash', 'removed'])
        ->and($registry->settable())->toBe(['pending', 'approved', 'spam', 'trash'])
        ->and($registry->shown())->toBe(['approved', 'removed'])
        ->and($registry->deletable())->toBe(['spam', 'trash'])
        ->and($registry->get('removed')->label)->toBe('Removed');
});

it('refuses a status registered twice, or keyed by anything but a plain word', function (): void {
    $registry = new CommentStatusRegistry;
    CoreCommentStatuses::register($registry);

    expect(fn () => $registry->register(new CommentStatus('spam', 'Spam again', shown: false, settable: true, deletable: true)))->toThrow(InvalidArgumentException::class, '[spam]')
        ->and(fn () => $registry->register(new CommentStatus('Held Back', 'Held back', shown: false, settable: true, deletable: false)))->toThrow(InvalidArgumentException::class, '[Held Back]');
});

it('names a status it doesn\'t know when asked for one', function (): void {
    expect(fn () => (new CommentStatusRegistry)->get('held'))->toThrow(InvalidArgumentException::class, '[held]')
        ->and(app(CommentStatusRegistry::class)->has('approved'))->toBeTrue();
});
