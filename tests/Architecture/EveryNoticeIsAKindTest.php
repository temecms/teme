<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeMail;
use App\Domain\Notices\NoticeRegistry;
use App\Domain\Notices\SentAsNotice;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

// Booted, to read the registry (tests/Architecture has no app otherwise).
uses(TestCase::class);

/**
 * Every notification class in app/, found from its file, so one added later is
 * covered without editing this test.
 *
 * @return list<class-string<Notification>>
 */
function einkNotificationClasses(): array
{
    // Read before the app boots, as a dataset is, so not app_path().
    $root = dirname(__DIR__, 2).'/app';
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, Notification::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('finds the notification classes, so the checks below are not empty', function (): void {
    expect(einkNotificationClasses())->toContain(NoticeMail::class);
});

it('sends every notification as a notice', function (string $class): void {
    expect(class_uses_recursive($class))->toContain(SentAsNotice::class);
})->with(fn (): array => array_combine(einkNotificationClasses(), array_map(fn (string $class): array => [$class], einkNotificationClasses())));

// NoticeMail is left out: its kind is a constructor argument, checked when it is sent (Notices::send()).
it('gives every notification a kind the registry knows', function (string $class): void {
    $kind = (new ReflectionClass($class))->newInstanceWithoutConstructor()->noticeKind();

    expect(app(NoticeRegistry::class)->get($kind))->not->toBeNull("{$class} is sent as the kind {$kind}, which is not registered.");
})->with(fn (): array => array_combine(
    array_diff(einkNotificationClasses(), [NoticeMail::class]),
    array_map(fn (string $class): array => [$class], array_diff(einkNotificationClasses(), [NoticeMail::class])),
));
