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
$routes->get('notifications', 'Notifications::index');
$routes->get('notifications/feed', 'Notifications::feed');
$routes->get('notifications/(:num)/open', 'Notifications::open/$1');
$routes->post('notifications/read-all', 'Notifications::readAll');

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
$routes->group('directory', ['filter' => 'can:directory.view'], static function (RouteCollection $routes): void {
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
