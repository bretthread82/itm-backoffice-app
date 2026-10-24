<?php

namespace Config;

use App\Core\AuditService;
use App\Core\CustomerService;
use App\Core\HelpService;
use App\Core\Mailer;
use App\Core\SupplierService;
use App\Core\ModuleService;
use App\Core\Mfa\DuoService;
use App\Core\Mfa\EmailCodeService;
use App\Core\Mfa\MfaPolicy;
use App\Core\Mfa\MfaService;
use App\Core\Mfa\TotpService;
use App\Core\NotificationService;
use App\Core\PasswordResetService;
use App\Core\PermissionService;
use App\Core\Tenancy\AccessScope;
use App\Core\Tenancy\ActiveCompany;
use App\Core\Tenancy\Memberships;
use App\Core\UserService;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function audit(bool $getShared = true): AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new AuditService();
    }

    public static function notifier(bool $getShared = true): NotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('notifier');
        }

        return new NotificationService();
    }

    public static function userService(bool $getShared = true): UserService
    {
        if ($getShared) {
            return static::getSharedInstance('userService');
        }

        return new UserService();
    }

    public static function mfa(bool $getShared = true): MfaService
    {
        return $getShared ? static::getSharedInstance('mfa') : new MfaService();
    }

    public static function mfaPolicy(bool $getShared = true): MfaPolicy
    {
        return $getShared ? static::getSharedInstance('mfaPolicy') : new MfaPolicy();
    }

    public static function totp(bool $getShared = true): TotpService
    {
        return $getShared ? static::getSharedInstance('totp') : new TotpService();
    }

    public static function emailCode(bool $getShared = true): EmailCodeService
    {
        return $getShared ? static::getSharedInstance('emailCode') : new EmailCodeService();
    }

    public static function duo(bool $getShared = true): DuoService
    {
        return $getShared ? static::getSharedInstance('duo') : new DuoService();
    }

    public static function mailer(bool $getShared = true): Mailer
    {
        if ($getShared) {
            return static::getSharedInstance('mailer');
        }

        return new Mailer();
    }

    public static function passwordReset(bool $getShared = true): PasswordResetService
    {
        if ($getShared) {
            return static::getSharedInstance('passwordReset');
        }

        return new PasswordResetService();
    }

    public static function help(bool $getShared = true): HelpService
    {
        return $getShared ? static::getSharedInstance('help') : new HelpService();
    }

    public static function permissions(bool $getShared = true): PermissionService
    {
        if ($getShared) {
            return static::getSharedInstance('permissions');
        }

        return new PermissionService();
    }

    /**
     * Where invoice files live (writable/uploads on this server). Tests replace it with Services::injectMock('fileStore', ...).
     */
    public static function fileStore(bool $getShared = true): \App\Core\Payables\Files\FileStore
    {
        return $getShared ? static::getSharedInstance('fileStore') : new \App\Core\Payables\Files\LocalFileStore();
    }

    public static function suppliers(bool $getShared = true): SupplierService
    {
        return $getShared ? static::getSharedInstance('suppliers') : new SupplierService();
    }

    public static function customers(bool $getShared = true): CustomerService
    {
        return $getShared ? static::getSharedInstance('customers') : new CustomerService();
    }

    public static function modules(bool $getShared = true): ModuleService
    {
        return $getShared ? static::getSharedInstance('modules') : new ModuleService();
    }

    public static function versions(bool $getShared = true): \App\Core\VersionService
    {
        return $getShared ? static::getSharedInstance('versions') : new \App\Core\VersionService();
    }

    public static function scope(bool $getShared = true): AccessScope
    {
        return $getShared ? static::getSharedInstance('scope') : new AccessScope();
    }

    public static function activeCompany(bool $getShared = true): ActiveCompany
    {
        return $getShared ? static::getSharedInstance('activeCompany') : new ActiveCompany(static::scope());
    }

    public static function memberships(bool $getShared = true): Memberships
    {
        return $getShared ? static::getSharedInstance('memberships') : new Memberships();
    }

    public static function timezone(bool $getShared = true): \App\Core\Timezone\TimezoneService
    {
        return $getShared ? static::getSharedInstance('timezone') : new \App\Core\Timezone\TimezoneService();
    }
}
