<?php

declare(strict_types=1);

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\NumberField;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => typesFresh());

it('gives a new type the built-in fields for the features it supports', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events', supports: ['seo']));

    $handles = collect(app(FieldRegistry::class)->forType('event'))->pluck('handle')->sort()->values()->all();

    expect($handles)->toBe(['article_type', 'canonical', 'insertions_off', 'max_snippet', 'meta_description', 'noarchive', 'noindex', 'nosnippet', 'page_type', 'primary_category', 'seo_title', 'social_description', 'social_image', 'social_title']);
});

it('lets two types each have a field called price', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('product', 'Product', 'Products', 'products'));
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events'));
    $fields = app(FieldRegistry::class);

    $fields->register(new Field('01K6A0P1CE000000000000PR01', 'price', 'Price', 'What it costs.', new NumberField, ['product'], '0.1.0'));
    $fields->register(new Field('01K6A0P1CE000000000000PR02', 'price', 'Price', 'The ticket price.', new NumberField, ['event'], '0.1.0'));

    expect(fn () => $fields->verify(app(ContentTypeRegistry::class)))->not->toThrow(Throwable::class);
});

it('refuses two fields with one handle on the same type', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events'));
    $fields = app(FieldRegistry::class);

    $fields->register(new Field('01K6A0P1CE000000000000PR03', 'price', 'Price', 'One.', new NumberField, ['event'], '0.1.0'));
    $fields->register(new Field('01K6A0P1CE000000000000PR04', 'price', 'Price', 'Two.', new NumberField, ['event'], '0.1.0'));

    expect(fn () => $fields->verify(app(ContentTypeRegistry::class)))->toThrow(InvalidArgumentException::class, 'price');
});
