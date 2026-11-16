<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Every column Teme keeps encrypted under APP_KEY, the list ReencryptSecrets
 * works through (I-119). A model's encrypted cast or a call that
 * encrypts into a table must have its column here:
 * tests/Feature/Security/EncryptedValuesTest.php fails until it does.
 */
final class EncryptedValues
{
    /**
     * Every encrypted column, in the order a run takes them.
     *
     * @return list<EncryptedColumn>
     */
    public function all(): array
    {
        return [
            new EncryptedColumn('settings', 'secrets', 'group_key', EncryptedForm::Json, 'Settings’ secrets', 'settings group'),
            // Fortify writes these two itself (I-100); ReencryptSecrets is the
            // only Teme code that rewrites them.
            new EncryptedColumn('users', 'two_factor_secret', 'id', EncryptedForm::Serialized, 'Two-factor secrets', 'person'),
            new EncryptedColumn('users', 'two_factor_recovery_codes', 'id', EncryptedForm::Serialized, 'Two-factor recovery codes', 'person'),
            new EncryptedColumn('backup_destinations', 'credentials', 'id', EncryptedForm::Json, 'Backup places’ credentials', 'backup place'),
            new EncryptedColumn('contents', 'reader_password', 'id', EncryptedForm::String, 'Reader passwords', 'item'),
            new EncryptedColumn('import_sources', 'credential', 'id', EncryptedForm::String, 'Import sources’ passwords', 'import source'),
            new EncryptedColumn('fediverse_actors', 'private_key', 'id', EncryptedForm::String, 'Fediverse accounts’ private keys', 'fediverse account'),
            new EncryptedColumn('extension_sources', 'credential', 'id', EncryptedForm::String, 'Package sources’ keys', 'package source'),
        ];
    }
}
