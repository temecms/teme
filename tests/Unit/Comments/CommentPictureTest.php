<?php

declare(strict_types=1);

use App\Domain\Comments\CommentPicture;

it('takes the first letter of the first and last words, whole and upper-cased (I-269)', function (string $name, string $initials): void {
    expect(CommentPicture::initials($name))->toBe($initials);
})->with([
    'two words' => ['Ada Guest', 'AG'],
    'three words' => ['Ada Love Lace', 'AL'],
    'lower case' => ['bell hooks', 'BH'],
    'letters with marks, kept whole' => ['Ọlábísí Adébáyọ̀', 'ỌA'],
    'one word' => ['émile', 'É'],
    'no letter at all' => ['  123 ', '?'],
]);

it('colours a picture from the name alone, whatever its case, one of eight hues', function (): void {
    expect(CommentPicture::hue('Ada Guest'))->toBe(7)
        ->and(CommentPicture::hue('ADA GUEST'))->toBe(7)
        ->and(CommentPicture::hue('Ọlábísí Adébáyọ̀'))->toBeGreaterThanOrEqual(0)->toBeLessThan(CommentPicture::HUES);
});
