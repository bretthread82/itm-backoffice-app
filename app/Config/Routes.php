<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Dashboard::index');

service('auth')->routes($routes);

// Two-step verification: challenge and enrolment (the user comes from Shield's session, never the request)
$routes->get('mfa/challenge', 'MfaController::challenge');
$routes->post('mfa/challenge', 'MfaController::verify');
$routes->post('mfa/email/send', 'MfaController::sendEmail');
$routes->post('mfa/cancel', 'MfaController::cancel');
$routes->get('mfa/enrol', 'MfaController::enrol');
$routes->match(['get', 'post'], 'mfa/enrol/totp', 'MfaController::enrolTotp');
$routes->match(['get', 'post'], 'mfa/enrol/email', 'MfaController::enrolEmail');
$routes->get('mfa/duo/start', 'MfaController::duoStart');
$routes->get('mfa/duo/callback', 'MfaController::duoCallback');

// Forgot / reset password (public, rate-limited)
$routes->get('forgot-password', 'PasswordReset::forgot');
$routes->post('forgot-password', 'PasswordReset::sendLink');
$routes->get('reset-password/([a-f0-9]{64})', 'PasswordReset::open/$1');
$routes->get('reset-password', 'PasswordReset::form');
$routes->post('reset-password', 'PasswordReset::reset');

// Microsoft Entra sign-in (inert unless entra.enabled and configured)
$routes->get('auth/entra/login', 'EntraAuth::login');
$routes->get('auth/entra/callback', 'EntraAuth::callback');

// Notifications (own only)
$routes->post('company-context', 'CompanyContext::set');

$routes->get('notifications', 'Notifications::index');
$routes->get('notifications/feed', 'Notifications::feed');
$routes->get('notifications/(:num)/open', 'Notifications::open/$1');
$routes->post('notifications/read-all', 'Notifications::readAll');

// Help: any signed-in user; HelpService hides topics by permission (and 404s the rest)
$routes->get('help', 'Help::index');
$routes->get('help/faq', 'Help::faq');
$routes->get('help/(:segment)', 'Help::topic/$1');

// Self-service (always the signed-in user)
$routes->get('profile', 'Profile::index');
$routes->post('profile', 'Profile::update');
$routes->post('profile/avatar', 'Profile::avatarUpload');
$routes->post('profile/avatar/remove', 'Profile::avatarRemove');
$routes->get('avatar/(:num)', 'Profile::avatar/$1');
$routes->post('profile/requests', 'Profile::requestChange');
$routes->post('profile/requests/(:num)/cancel', 'Profile::cancelRequest/$1');
$routes->get('profile/security', 'Profile::security');
$routes->post('profile/security/password', 'Profile::changePassword');
$routes->get('profile/notifications', 'Profile::notificationSettings');
$routes->get('profile/mfa', 'ProfileMfa::index');
$routes->post('profile/mfa/confirm', 'ProfileMfa::confirm');
$routes->post('profile/mfa/disable/(:segment)', 'ProfileMfa::disable/$1');
$routes->post('profile/mfa/recovery', 'ProfileMfa::regenerate');
$routes->get('profile/mfa/codes', 'ProfileMfa::codes');
$routes->post('profile/mfa/devices/forget', 'ProfileMfa::forgetDevices');
$routes->post('profile/notifications', 'Profile::saveNotificationSettings');

// Staff directory
// ---- Invoices module -----------------------------------------------------------------------
// Visibility inside these screens is decided per company by AccessScope; the permission only opens the door.
$routes->group('invoices', ['filter' => 'can:invoices.view'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Invoices::index');
    $routes->get('overview', 'InvoiceOverview::index');
    $routes->get('export.csv', 'Invoices::export');
    $routes->get('new', 'Invoices::new');
    $routes->get('types', 'Invoices::types');
    $routes->post('/', 'Invoices::create');
    $routes->get('delegations', 'InvoiceDelegations::index');
    $routes->post('delegations', 'InvoiceDelegations::create');
    $routes->post('delegations/(:num)/end', 'InvoiceDelegations::end/$1');
    $routes->get('(:num)', 'Invoices::show/$1');
    $routes->get('(:num)/edit', 'Invoices::edit/$1');
    $routes->post('(:num)', 'Invoices::update/$1');
    $routes->get('(:num)/file', 'Invoices::file/$1');
    $routes->post('(:num)/documents', 'Invoices::addDocument/$1');
    $routes->get('(:num)/documents/(:num)', 'Invoices::document/$1/$2');
    $routes->post('(:num)/submit', 'Invoices::submit/$1');
    $routes->post('(:num)/resubmit', 'Invoices::resubmit/$1');
    $routes->post('(:num)/cancel', 'Invoices::cancel/$1');
    $routes->post('(:num)/note', 'Invoices::note/$1');
    $routes->post('(:num)/act', 'Invoices::act/$1');
    $routes->post('(:num)/claim', 'Invoices::claim/$1');
    $routes->post('(:num)/release', 'Invoices::release/$1');
});

$routes->get('approvals', 'Approvals::index', ['filter' => 'can:invoices.view']);

$routes->group('reports', ['filter' => 'can:invoices.reports'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Reports::index');
    $routes->get('outstanding.csv', 'Reports::outstandingCsv');
    $routes->get('suppliers', 'Reports::suppliers');
    $routes->get('suppliers.csv', 'Reports::suppliersCsv');
});

$routes->group('invoices-admin', ['namespace' => 'App\Controllers\InvoiceAdmin'], static function (RouteCollection $routes): void {
    $routes->group('', ['filter' => 'can:invoices.workflow'], static function (RouteCollection $routes): void {
        // Suppliers moved to core: admin/suppliers (old URLs redirect).
        $routes->get('suppliers', '\App\Controllers\Admin\Suppliers::legacy');
        $routes->get('suppliers/(:any)', '\App\Controllers\Admin\Suppliers::legacy/');

        $routes->get('default-invoice-types', 'DefaultInvoiceTypes::index');
        $routes->post('default-invoice-types', 'DefaultInvoiceTypes::create');
        $routes->post('default-invoice-types/assign', 'DefaultInvoiceTypes::assign');
        $routes->post('default-invoice-types/(:num)', 'DefaultInvoiceTypes::update/$1');
        $routes->post('default-invoice-types/(:num)/delete', 'DefaultInvoiceTypes::delete/$1');
        $routes->post('invoice-types/(:num)/priority', 'DefaultInvoiceTypes::setTypePriority/$1');

        $routes->get('routes', 'Routes::index');
        $routes->get('routes/new', 'Routes::new');
        $routes->post('routes', 'Routes::create');
        $routes->get('routes/(:num)/edit', 'Routes::edit/$1');
        $routes->post('routes/(:num)', 'Routes::update/$1');

        $routes->get('route-variants', 'RouteVariants::index');
        $routes->get('route-variants/new', 'RouteVariants::new');
        $routes->post('route-variants', 'RouteVariants::create');
        $routes->get('route-variants/(:num)/edit', 'RouteVariants::edit/$1');
        $routes->post('route-variants/(:num)', 'RouteVariants::update/$1');
        $routes->post('route-variants/(:num)/delete', 'RouteVariants::delete/$1');
    });

    $routes->group('', ['filter' => 'can:invoices.manage'], static function (RouteCollection $routes): void {
        $routes->get('teams', 'Teams::index');
        $routes->get('teams/new', 'Teams::new');
        $routes->post('teams', 'Teams::create');
        $routes->get('teams/(:num)/edit', 'Teams::edit/$1');
        $routes->post('teams/(:num)', 'Teams::update/$1');
    });
});

$routes->group('directory',['filter' => 'can:directory.view'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Directory::index');
    $routes->get('(:num)', 'Directory::show/$1');
});

$routes->group('admin', ['namespace' => 'App\Controllers\Admin'], static function (RouteCollection $routes): void {
    // Users
    $routes->get('users', 'Users::index', ['filter' => 'can:users.view']);
    $routes->get('users/new', 'Users::new', ['filter' => 'can:users.create']);
    $routes->post('users', 'Users::create', ['filter' => 'can:users.create']);
    $routes->get('users/(:num)/edit', 'Users::edit/$1', ['filter' => 'can:users.edit,users.assign_roles']);
    $routes->post('users/(:num)', 'Users::update/$1', ['filter' => 'can:users.edit,users.assign_roles']);
    $routes->post('users/(:num)/status', 'Users::status/$1', ['filter' => 'can:users.delete']);
    $routes->post('users/(:num)/reset-password', 'Users::resetPassword/$1', ['filter' => 'can:users.edit']);
    $routes->post('users/(:num)/reset-mfa', 'Users::resetMfa/$1', ['filter' => 'can:users.edit']);
    $routes->post('users/(:num)/delete', 'Users::delete/$1', ['filter' => 'can:users.delete']);

    // Roles
    $routes->group('suppliers', ['filter' => 'can:suppliers.manage'], static function (RouteCollection $routes): void {
        $routes->get('/', 'Suppliers::index');
        $routes->post('/', 'Suppliers::create');
        $routes->get('upload', 'Suppliers::uploadForm');
        $routes->get('upload/template', 'Suppliers::template');
        $routes->post('upload/preview', 'Suppliers::uploadPreview');
        $routes->post('upload/commit', 'Suppliers::uploadCommit');
        $routes->post('(:num)', 'Suppliers::update/$1');
    });

    $routes->get('modules', 'Modules::index', ['filter' => 'can:modules.manage']);
    $routes->post('modules/(:segment)', 'Modules::toggle/$1', ['filter' => 'can:modules.manage']);

    $routes->group('companies', ['filter' => 'can:companies.manage'], static function (RouteCollection $routes): void {
        $routes->get('/', 'Companies::index');
        $routes->get('new', 'Companies::new');
        $routes->post('/', 'Companies::create');
        $routes->get('(:num)/edit', 'Companies::edit/$1');
        $routes->post('(:num)', 'Companies::update/$1');
        $routes->post('groups', 'Companies::saveGroup');
        $routes->post('groups/(:num)/delete', 'Companies::deleteGroup/$1');
    });

    $routes->get('users/(:num)/companies', 'Memberships::edit/$1', ['filter' => 'can:tenancy.memberships']);
    $routes->post('users/(:num)/companies', 'Memberships::save/$1', ['filter' => 'can:tenancy.memberships']);

    $routes->get('group-level', 'GroupLevel::index', ['filter' => 'can:tenancy.group_level']);
    $routes->post('group-level', 'GroupLevel::add', ['filter' => 'can:tenancy.group_level']);
    $routes->post('group-level/(:num)/remove', 'GroupLevel::remove/$1', ['filter' => 'can:tenancy.group_level']);

    $routes->group('roles', ['filter' => 'can:roles.manage'], static function (RouteCollection $routes): void {
        $routes->get('/', 'Roles::index');
        $routes->get('new', 'Roles::new');
        $routes->post('/', 'Roles::create');
        $routes->get('(:num)/edit', 'Roles::edit/$1');
        $routes->post('(:num)', 'Roles::update/$1');
        $routes->post('(:num)/delete', 'Roles::delete/$1');
    });

    // Departments
    $routes->group('departments', ['filter' => 'can:departments.manage'], static function (RouteCollection $routes): void {
        $routes->get('/', 'Departments::index');
        $routes->post('/', 'Departments::save');
        $routes->post('(:num)/delete', 'Departments::delete/$1');
    });

    // Multi-factor authentication policy
    $routes->get('mfa', 'Mfa::index', ['filter' => 'can:mfa.manage']);
    $routes->post('mfa', 'Mfa::save', ['filter' => 'can:mfa.manage']);

    // Microsoft Entra
    $routes->group('entra', ['filter' => 'can:entra.manage'], static function (RouteCollection $routes): void {
        $routes->get('/', 'Entra::index');
        $routes->post('test', 'Entra::test');
        $routes->post('sync', 'Entra::sync');
        $routes->post('mappings', 'Entra::addMapping');
        $routes->post('mappings/delete', 'Entra::deleteMapping');
    });

    // Profile change requests
    $routes->get('requests', 'Requests::index', ['filter' => 'can:users.edit']);
    $routes->post('requests/(:num)', 'Requests::decide/$1', ['filter' => 'can:users.edit']);

    $routes->get('audit', 'Audit::index', ['filter' => 'can:audit.view']);
});
