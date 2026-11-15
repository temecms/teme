<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

/**
 * Every sentence the management API says to whoever calls it, beside the
 * delivery API's ApiWords, so its words live in one place.
 */
final class ManageApiWords
{
    /**
     * unauthorized's detail.
     */
    public static function unauthorized(): string
    {
        return __('Sign in with a valid key: send it as Authorization: Bearer followed by the key.');
    }

    /**
     * api_off's detail.
     */
    public static function off(): string
    {
        return __('This site’s management API is switched off.');
    }

    /**
     * forbidden's detail when the key lacks an ability.
     */
    public static function missingAbility(ApiAbility $ability): string
    {
        return __('This key wasn’t given the :ability ability.', ['ability' => $ability->value]);
    }

    /**
     * forbidden's detail when the key's person may not do it.
     */
    public static function notPermitted(): string
    {
        return __('The person this key acts as isn’t allowed to do this.');
    }

    /**
     * invalid_parameter's detail.
     */
    public static function invalid(): string
    {
        return __('One or more fields can’t be used.');
    }

    /**
     * duplicate's detail.
     */
    public static function duplicate(): string
    {
        return __('This file is already in the media library. Its ID is in existing_id.');
    }

    /**
     * not_found's detail for a taxonomy.
     */
    public static function noTaxonomy(string $taxonomy): string
    {
        return __('This site has no taxonomy :taxonomy.', ['taxonomy' => $taxonomy]);
    }

    /**
     * not_found's detail for a media item.
     */
    public static function noMedia(string $id): string
    {
        return __('No media item has the ID :id.', ['id' => $id]);
    }

    /**
     * not_supported_yet's detail for an HTML body.
     */
    public static function htmlNotSupported(): string
    {
        return __('HTML bodies can’t be converted yet. Send body as a Tiptap document.');
    }

    /**
     * The detail of a publish refused after its draft was made.
     */
    public static function keptAsDraft(): string
    {
        return __('The draft was created, but it couldn’t be published or scheduled.');
    }
}
