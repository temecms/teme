<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

/**
 * The kinds of publisher a site may say it is (I-876): nobody in
 * particular, an organization of one of six kinds, a local business of one
 * of five, or a person; each but nobody stored as its schema.org type's
 * name, which the publisher's node takes as its @type.
 */
final class PublisherKinds
{
    /** Nobody in particular: the site names no publisher. */
    public const string NOBODY = '';

    public const string PERSON = 'Person';

    /** The organizations, each schema.org's name to its label. */
    public const array ORGANIZATIONS = [
        'Organization' => 'Organization',
        'NewsMediaOrganization' => 'News media organization',
        'Corporation' => 'Corporation',
        'NGO' => 'NGO',
        'EducationalOrganization' => 'Educational organization',
        'GovernmentOrganization' => 'Government organization',
    ];

    /** The local businesses, each one place with an address (I-708). */
    public const array BUSINESSES = [
        'LocalBusiness' => 'Local business',
        'Store' => 'Store',
        'Restaurant' => 'Restaurant',
        'LegalService' => 'Legal service',
        'AccountingService' => 'Accounting service',
        'MedicalBusiness' => 'Medical business',
    ];

    /**
     * Every kind, its value to its label, in the order the select lists them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [self::NOBODY => 'Nobody in particular', ...self::ORGANIZATIONS, ...self::BUSINESSES, self::PERSON => 'A person'];
    }

    /** Whether the kind is an organization, a local business included. */
    public static function isOrganization(string $kind): bool
    {
        return isset(self::ORGANIZATIONS[$kind]) || isset(self::BUSINESSES[$kind]);
    }

    /** Whether the kind is a local business, with an address and opening hours. */
    public static function isBusiness(string $kind): bool
    {
        return isset(self::BUSINESSES[$kind]);
    }
}
