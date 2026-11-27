<?php

/**
 * What a plugin's manifest declares for an image service
 * (docs/guides/developers/plugins/image-service.md): its label and the one
 * host its addresses are on, how both are checked, and the consent's line
 * naming them.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Images;

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use Teme\Contracts\Media\HasImageService;
use Teme\Contracts\Plugins\Capability;

final class ImageServiceDeclaration
{
    /** A host, or `*.` and a domain: the shape `csp` takes its hosts in. */
    public const string HOST = '/^(?:\*\.)?(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D';

    /**
     * The declaration, or null when there is none or it isn't well formed:
     * the validator refused it first.
     *
     * @return array{label: string, host: string}|null
     */
    public static function of(Manifest $manifest): ?array
    {
        $declared = $manifest->contributes()['imageService'] ?? null;

        if (! is_array($declared) || ! is_string($declared['label'] ?? null) || ! is_string($declared['host'] ?? null) || preg_match(self::HOST, $declared['host']) !== 1) {
            return null;
        }

        return ['label' => $declared['label'], 'host' => $declared['host']];
    }

    /**
     * Why the declaration can't be used, or nothing when it can. The
     * capability is checked only when the capabilities are given, as when a
     * manifest is read.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<string>|null  $capabilities
     *
     * @return list<string>
     */
    public static function errors(array $contributes, ?array $capabilities): array
    {
        if (! array_key_exists('imageService', $contributes)) {
            return [];
        }

        $declared = $contributes['imageService'];

        if (! is_array($declared) || array_diff(array_keys($declared), ['label', 'host']) !== []) {
            return [__('Its imageService must be an object of a label and a host.')];
        }

        $errors = [];
        $label = $declared['label'] ?? null;

        if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 40) {
            $errors[] = __('Its image service’s label must be text of 1 to 40 characters.');
        }

        if (! is_string($declared['host'] ?? null) || preg_match(self::HOST, $declared['host']) !== 1) {
            $errors[] = __('Its image service’s host must be a host name, such as images.example.com, or *. and a domain, such as *.example.com, which covers its subdomains but not example.com itself.');
        }

        if ($capabilities !== null && ! in_array(Capability::MediaRead->value, $capabilities, true)) {
            $errors[] = __('It adds an image service, but the plugin doesn’t ask for :capability.', ['capability' => Capability::MediaRead->value]);
        }

        return $errors;
    }

    /**
     * What a starting plugin's class must hand over for what it declares.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public static function startErrors(array $contributes, object $plugin): array
    {
        $declared = array_key_exists('imageService', $contributes);

        if ($declared && ! $plugin instanceof HasImageService) {
            return [__('It declares an image service but doesn’t implement :interface.', ['interface' => HasImageService::class])];
        }

        if (! $declared && $plugin instanceof HasImageService) {
            return [__('It hands over an image service but declares none.')];
        }

        return [];
    }

    /**
     * The consent's line naming the service and its host, in the admin's
     * language; none for a plugin without one, or not agreed media.read.
     *
     * @param  list<string>|null  $agreed  the capabilities agreed, or null for what it asks
     *
     * @return list<string>
     */
    public static function consentWords(Manifest $manifest, ?array $agreed = null): array
    {
        $declared = self::of($manifest);

        if ($declared === null || ($agreed !== null && ! in_array(Capability::MediaRead->value, $agreed, true))) {
            return [];
        }

        $label = ManifestText::for($manifest->code())->text($declared['label'], app()->getLocale());

        return [__('Give your images’ addresses from :label, at :host, in place of the sizes made at upload.', ['label' => $label, 'host' => $declared['host']])];
    }
}
