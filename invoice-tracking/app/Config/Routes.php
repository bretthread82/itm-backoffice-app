<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Login, logout and emailed sign-in links only. No self-registration.
service('auth')->routes($routes, ['except' => ['register']]);

// Load balancer health check: public, no session, no details.
$routes->get('health', 'Health::index');

// Microsoft sign-in. Both refuse everything unless an administrator has switched it on.
$routes->get('auth/microsoft', 'MicrosoftAuth::start');
$routes->get('auth/microsoft/callback', 'MicrosoftAuth::callback');

// Read-only JSON API. Bearer tokens only (never the browser session); no CSRF because there is no cookie
// to forge. The spec itself is public; every data endpoint needs a token with the api.read scope.
$routes->get('api/v1/openapi.json', 'Api\V1\Spec::index', ['filter' => 'api-throttle']);
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => ['api-throttle', 'api-auth:api.read']], static function (RouteCollection $routes): void {
    $routes->get('me', 'Me::index');
    $routes->get('companies', 'Companies::index');
    $routes->get('invoices', 'Invoices::index');
    $routes->get('invoices/(:num)', 'Invoices::show/$1');
    $routes->get('invoices/(:num)/timeline', 'Invoices::timeline/$1');
    $routes->get('invoices/(:num)/file', 'Invoices::file/$1');
    $routes->get('invoices/(:num)/documents/(:num)', 'Invoices::document/$1/$2');
    $routes->get('approvals', 'Approvals::index');
    $routes->get('reports/outstanding', 'Reports::outstanding');
    $routes->match(['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], '(:any)', 'Fallback::index');
});

// Signed-in users who may still need to change their password.
$routes->group('', ['filter' => 'session'], static function (RouteCollection $routes): void {
    $routes->get('account/password', 'Account::password');
    $routes->post('account/password', 'Account::updatePassword');
});

// The application proper (signed in, password already changed).
$routes->group('', ['filter' => ['session', 'force-reset']], static function (RouteCollection $routes): void {
    $routes->get('/', 'Dashboard::index');
    $routes->get('help', 'Help::index');

    $routes->get('api/docs', 'Api\Docs::index');

    $routes->get('invoices', 'Invoices::index');
    $routes->get('invoices/export.csv', 'Invoices::export');
    $routes->get('invoices/new', 'Invoices::new');
    $routes->get('invoices/types', 'Invoices::types');
    $routes->post('invoices', 'Invoices::create');
    $routes->get('invoices/(:num)', 'Invoices::show/$1');
    $routes->get('invoices/(:num)/edit', 'Invoices::edit/$1');
    $routes->post('invoices/(:num)', 'Invoices::update/$1');
    $routes->get('invoices/(:num)/file', 'Invoices::file/$1');
    $routes->post('invoices/(:num)/documents', 'Invoices::addDocument/$1');
    $routes->get('invoices/(:num)/documents/(:num)', 'Invoices::document/$1/$2');
    $routes->post('invoices/(:num)/submit', 'Invoices::submit/$1');
    $routes->post('invoices/(:num)/resubmit', 'Invoices::resubmit/$1');
    $routes->post('invoices/(:num)/cancel', 'Invoices::cancel/$1');
    $routes->post('invoices/(:num)/note', 'Invoices::note/$1');
    $routes->post('invoices/(:num)/act', 'Invoices::act/$1');
    $routes->post('invoices/(:num)/claim', 'Invoices::claim/$1');
    $routes->post('invoices/(:num)/release', 'Invoices::release/$1');

    $routes->post('company-context', 'CompanyContext::set');

    $routes->get('logo/group', 'Logos::group');
    $routes->get('logo/company/(:num)', 'Logos::company/$1');

    $routes->get('approvals', 'Approvals::index');

    $routes->get('reports', 'Reports::index');
    $routes->get('reports/outstanding.csv', 'Reports::outstandingCsv');
    $routes->get('reports/suppliers', 'Reports::suppliers');
    $routes->get('reports/suppliers.csv', 'Reports::suppliersCsv');

    $routes->get('notifications', 'Notifications::index');
    $routes->get('notifications/unread', 'Notifications::unread');
    $routes->get('notifications/(:num)/open', 'Notifications::open/$1');
    $routes->get('notifications/(:num)/read', 'Notifications::markRead/$1');
    $routes->post('notifications/read-all', 'Notifications::readAll');

    $routes->get('account/notifications', 'Account::notifications');
    $routes->post('account/notifications', 'Account::saveNotifications');
    $routes->get('account/api-tokens', 'Account::apiTokens');
    $routes->post('account/api-tokens/(:num)/revoke', 'Account::revokeApiToken/$1');
    $routes->get('account/delegations', 'Account::delegations');
    $routes->post('account/delegations', 'Account::createDelegation');
    $routes->post('account/delegations/(:num)/end', 'Account::endDelegation/$1');
});

// Workflow and day-to-day administration: managers and administrators.
$routes->group('admin', [
    'filter'    => ['session', 'force-reset', 'manager'],
    'namespace' => 'App\Controllers\Admin',
], static function (RouteCollection $routes): void {
    $routes->get('/', 'Companies::index');

    $routes->get('companies', 'Companies::index');
    $routes->get('companies/new', 'Companies::new');
    $routes->post('companies', 'Companies::create');
    $routes->get('companies/(:num)/edit', 'Companies::edit/$1');
    $routes->post('companies/(:num)', 'Companies::update/$1');

    // Long-term trends from the separate analytics database.
    $routes->get('analytics', 'Analytics::index');
    $routes->get('analytics/volume.csv', 'Analytics::volumeCsv');
    $routes->get('analytics/cycle-time', 'Analytics::cycleTime');
    $routes->get('analytics/cycle-time.csv', 'Analytics::cycleCsv');
    $routes->get('analytics/backlog', 'Analytics::backlog');
    $routes->get('analytics/backlog.csv', 'Analytics::backlogCsv');

    $routes->get('groups', 'Groups::index');
    $routes->post('groups', 'Groups::create');
    $routes->post('groups/(:num)', 'Groups::update/$1');
    $routes->post('groups/(:num)/delete', 'Groups::delete/$1');

    $routes->get('users', 'Users::index');
    $routes->get('users/new', 'Users::new');
    $routes->post('users', 'Users::create');
    $routes->get('users/(:num)/edit', 'Users::edit/$1');
    $routes->post('users/(:num)', 'Users::update/$1');
    $routes->post('users/(:num)/invite', 'Users::invite/$1');
    $routes->post('users/(:num)/transfer-work', 'Users::transferWork/$1');
    $routes->post('users/(:num)/reset-password', 'Users::resetPassword/$1');
    $routes->post('users/(:num)/unlink-microsoft', 'Users::unlinkMicrosoft/$1');
    $routes->post('users/invite-pending', 'Users::invitePending');

    $routes->get('users/upload', 'UserBulkUpload::index');
    $routes->get('users/upload/template', 'UserBulkUpload::template');
    $routes->post('users/upload/preview', 'UserBulkUpload::preview');
    $routes->post('users/upload/commit', 'UserBulkUpload::commit');

    $routes->get('teams', 'Teams::index');
    $routes->get('teams/new', 'Teams::new');
    $routes->post('teams', 'Teams::create');
    $routes->get('teams/(:num)/edit', 'Teams::edit/$1');
    $routes->post('teams/(:num)', 'Teams::update/$1');

    $routes->get('import', 'RoutingImport::index');
    $routes->post('import', 'RoutingImport::upload');
    $routes->get('import/(:num)', 'RoutingImport::review/$1');
    $routes->post('import/(:num)/commit', 'RoutingImport::commit/$1');
    $routes->post('import/(:num)/discard', 'RoutingImport::discard/$1');

    $routes->get('group-level-activity','GroupLevelActivity::index');
    $routes->get('group-level-activity.csv', 'GroupLevelActivity::csv');

    $routes->get('delegations', 'Delegations::index');
    $routes->post('delegations', 'Delegations::create');
    $routes->post('delegations/(:num)/end', 'Delegations::end/$1');

    $routes->get('audit', 'AuditLog::index');
});

// Workflow settings (invoice types, suppliers, approval routes, route variants): administrators, managers and
// the narrower Workflow Manager level. Nothing else lives in this group.
$routes->group('admin', [
    'filter'    => ['session', 'force-reset', 'workflow'],
    'namespace' => 'App\Controllers\Admin',
], static function (RouteCollection $routes): void {
    $routes->get('suppliers', 'Suppliers::index');
    $routes->post('suppliers', 'Suppliers::create');
    $routes->get('suppliers/upload', 'Suppliers::uploadForm');
    $routes->get('suppliers/upload/template', 'Suppliers::template');
    $routes->post('suppliers/upload/preview', 'Suppliers::uploadPreview');
    $routes->post('suppliers/upload/commit', 'Suppliers::uploadCommit');
    $routes->post('suppliers/(:num)', 'Suppliers::update/$1');

    $routes->get('default-invoice-types', 'DefaultInvoiceTypes::index');
    $routes->post('default-invoice-types', 'DefaultInvoiceTypes::create');
    $routes->post('default-invoice-types/assign', 'DefaultInvoiceTypes::assign');
    $routes->get('default-invoice-types/upload', 'DefaultInvoiceTypes::uploadForm');
    $routes->get('default-invoice-types/upload/template', 'DefaultInvoiceTypes::template');
    $routes->post('default-invoice-types/upload/preview', 'DefaultInvoiceTypes::uploadPreview');
    $routes->post('default-invoice-types/upload/commit', 'DefaultInvoiceTypes::uploadCommit');
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

// System administration: administrators only.
$routes->group('admin', [
    'filter'    => ['session', 'force-reset', 'admin'],
    'namespace' => 'App\Controllers\Admin',
], static function (RouteCollection $routes): void {
    $routes->get('sharepoint', 'SharePointConnections::index');
    $routes->get('sharepoint/new', 'SharePointConnections::new');
    $routes->post('sharepoint', 'SharePointConnections::create');
    $routes->get('sharepoint/(:num)/edit', 'SharePointConnections::edit/$1');
    $routes->post('sharepoint/(:num)', 'SharePointConnections::update/$1');
    $routes->post('sharepoint/(:num)/test', 'SharePointConnections::test/$1');
    $routes->post('sharepoint/(:num)/delete', 'SharePointConnections::delete/$1');

    $routes->get('sharepoint-sync', 'SharePointSync::index');
    $routes->post('sharepoint-sync/run', 'SharePointSync::run');
    $routes->post('sharepoint-sync/(:num)/retry', 'SharePointSync::retry/$1');

    $routes->get('api-tokens', 'ApiTokens::index');
    $routes->post('api-tokens', 'ApiTokens::create');
    $routes->post('api-tokens/(:num)/revoke', 'ApiTokens::revoke/$1');

    $routes->get('settings', 'Settings::index');
    $routes->post('settings', 'Settings::save');
    $routes->post('settings/microsoft', 'Settings::saveMicrosoft');
    $routes->post('settings/logo', 'Settings::saveLogo');

    $routes->get('group-level', 'GroupLevelUsers::index');
    $routes->post('group-level', 'GroupLevelUsers::add');
    $routes->post('group-level/(:num)/remove', 'GroupLevelUsers::remove/$1');
});
