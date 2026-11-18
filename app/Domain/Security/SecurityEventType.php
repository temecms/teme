<?php

/**
 * Every kind of event the security log records. The noisy ones are counted per
 * hour instead of stored one by one.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Security;

enum SecurityEventType: string
{
    case SignInSucceeded = 'signin.succeeded';
    case SignInFailed = 'signin.failed';
    case SignInThrottled = 'signin.throttled';
    case PasswordChanged = 'password.changed';
    case PasswordReset = 'password.reset';
    case PasswordHashUpgraded = 'password.hash_upgraded';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case TwoFactorFailed = 'two_factor.failed';
    case RecoveryCodesGenerated = 'two_factor.recovery_codes_generated';
    case RecoveryCodeUsed = 'two_factor.recovery_code_used';
    case UserInvited = 'user.invited';
    case InvitationAccepted = 'user.invitation_accepted';
    case RoleChanged = 'user.role_changed';
    case UserSuspended = 'user.suspended';
    case UserReactivated = 'user.reactivated';
    case UserDeleted = 'user.deleted';
    case RedirectCreated = 'redirect.created';
    case RedirectChanged = 'redirect.changed';
    case RedirectSwitched = 'redirect.switched';
    case RedirectDeleted = 'redirect.deleted';
    case RedirectsImported = 'redirect.imported';
    case ImportSourceConnected = 'import.source_connected';
    case ImportStarted = 'import.started';
    case ImportEnded = 'import.ended';
    case ImportSourceForgotten = 'import.source_forgotten';
    case PasswordResetLinkIssued = 'password.reset_link_issued';
    case SecuritySettingsChanged = 'settings.security_changed';
    case SecretsReencrypted = 'secrets.reencrypted';
    case InvitationMailFailed = 'mail.invitation_failed';
    case ResetMailFailed = 'mail.reset_failed';
    case SiteCodeAdded = 'site_code.added';
    case SiteCodeChanged = 'site_code.changed';
    case SiteCodeSwitchedOn = 'site_code.switched_on';
    case SiteCodeSwitchedOff = 'site_code.switched_off';
    case SiteCodeDeleted = 'site_code.deleted';
    case ExportMade = 'export.made';
    case PageCacheCleared = 'page_cache.cleared';
    case BackupsSetUp = 'backup.set_up';
    case BackupDestinationAdded = 'backup.destination_added';
    case BackupDestinationChanged = 'backup.destination_changed';
    case BackupDestinationRemoved = 'backup.destination_removed';
    case BackupRecoveryKeyAdded = 'backup.recovery_key_added';
    case BackupRecoveryKeyReplaced = 'backup.recovery_key_replaced';
    case BackupRecoveryKeyChecked = 'backup.recovery_key_checked';
    case BackupRestoreStarted = 'backup.restore_started';
    case BackupRestoreEnded = 'backup.restore_ended';
    case PluginEnabled = 'plugin.enabled';
    case PluginDisabled = 'plugin.disabled';
    case PluginMigrated = 'plugin.migrated';
    case PluginDataRemoved = 'plugin.data_removed';
    case PluginForgotten = 'plugin.forgotten';
    case SiteSetUp = 'site.set_up';

    case ApiKeyCreated = 'api_key.created';
    case ApiKeyRevoked = 'api_key.revoked';
    case PluginScriptApproved = 'plugin_script.approved';
    case PluginScriptRefused = 'plugin_script.refused';
    case PluginScriptRevoked = 'plugin_script.revoked';

    /**
     * Counted per subject, address and hour instead of one row each.
     */
    public function isCounted(): bool
    {
        return in_array($this, [self::SignInFailed, self::SignInThrottled, self::TwoFactorFailed], true);
    }
}
