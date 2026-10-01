<?php

declare(strict_types=1);

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\PackagesController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\ErrorPagesController;
use App\Http\Controllers\AutorespondersController;
use App\Http\Controllers\DefaultAddressController;
use App\Http\Controllers\DeliverabilityController;
use App\Http\Controllers\EmailFiltersController;
use App\Http\Controllers\ForwardersController;
use App\Http\Controllers\EmailRoutingController;
use App\Http\Controllers\TrackDeliveryController;
use App\Http\Controllers\GlobalFiltersController;
use App\Http\Controllers\AddressImporterController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\BoxTrapperController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\EmailDiskUsageController;
use App\Http\Controllers\WebmailController;
use App\Http\Controllers\MysqlDatabasesController;
use App\Http\Controllers\MysqlWizardController;
use App\Http\Controllers\PhpmyadminController;
use App\Http\Controllers\RemoteMysqlController;
use App\Http\Controllers\ZoneEditorController;
use App\Http\Controllers\DynamicDnsController;
use App\Http\Controllers\MailingListsController;
use App\Http\Controllers\SpamFiltersController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\IndexesController;
use App\Http\Controllers\DiskUsageController;
use App\Http\Controllers\FilesController;
use App\Http\Controllers\HandlersController;
use App\Http\Controllers\MimeTypesController;
use App\Http\Controllers\PhpController;
use App\Http\Controllers\PhpIniController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\SshController;
use App\Http\Controllers\SslController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AlphaCP panel routes
|--------------------------------------------------------------------------
| Contract for future edits (any AI/dev):
|   * Every privileged action lives behind `auth` + `2fa` + `password.fresh`.
|   * Permission checks live in the `perm:` middleware, never in views.
|   * Module routes keep the module prefix (users.*, system.*, audit.* …) so
|   * Step 3+ modules can be dropped in without touching what exists here.
|   * Anything that changes state MUST write to the audit log.
*/

// ---------------------------------------------------------------------------
// Guest
// ---------------------------------------------------------------------------
Route::middleware('guest')->group(function (): void {
    Route::get('/', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');
});

// ---------------------------------------------------------------------------
// Authenticated (2FA challenge happens before anything else)
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function (): void {
    Route::get('/two-factor', [TwoFactorController::class, 'challenge'])->name('twofactor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('twofactor.verify');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

// ---------------------------------------------------------------------------
// Panel (auth + 2FA verified + fresh password)
// ---------------------------------------------------------------------------
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/domains', [DomainsController::class, 'index'])
        ->middleware('perm:domains.view')->name('domains.index');
    Route::post('/domains', [DomainsController::class, 'store'])
        ->middleware('perm:domains.manage')->name('domains.store');
    Route::delete('/domains/{domain}', [DomainsController::class, 'destroy'])
        ->middleware('perm:domains.manage')->name('domains.destroy');

    Route::get('/php', [PhpController::class, 'index'])
        ->middleware('perm:software.view')->name('php.index');
    Route::post('/php', [PhpController::class, 'update'])
        ->middleware('perm:software.manage')->name('php.update');
    Route::get('/php/ini', [PhpIniController::class, 'index'])
        ->middleware('perm:software.view')->name('php.ini');
    Route::post('/php/ini', [PhpIniController::class, 'update'])
        ->middleware('perm:software.manage')->name('php.ini.update');

    Route::get('/errorpages', [ErrorPagesController::class, 'index'])
        ->middleware('perm:errorpages.view')->name('errorpages.index');
    Route::post('/errorpages', [ErrorPagesController::class, 'update'])
        ->middleware('perm:errorpages.manage')->name('errorpages.update');

    Route::get('/indexes', [IndexesController::class, 'index'])
        ->middleware('perm:indexes.view')->name('indexes.index');
    Route::post('/indexes', [IndexesController::class, 'update'])
        ->middleware('perm:indexes.manage')->name('indexes.update');

    Route::get('/mime', [MimeTypesController::class, 'index'])
        ->middleware('perm:mime.view')->name('mime.index');
    Route::post('/mime', [MimeTypesController::class, 'store'])
        ->middleware('perm:mime.manage')->name('mime.store');
    Route::delete('/mime/{ext}', [MimeTypesController::class, 'destroy'])
        ->middleware('perm:mime.manage')->where('ext', '[A-Za-z0-9]{1,16}')->name('mime.destroy');

    Route::get('/handlers', [HandlersController::class, 'index'])
        ->middleware('perm:handlers.view')->name('handlers.index');
    Route::post('/handlers', [HandlersController::class, 'store'])
        ->middleware('perm:handlers.manage')->name('handlers.store');
    Route::delete('/handlers/{ext}', [HandlersController::class, 'destroy'])
        ->middleware('perm:handlers.manage')->where('ext', '[A-Za-z0-9]{1,16}')->name('handlers.destroy');

    Route::get('/files', [FilesController::class, 'index'])
        ->middleware('perm:files.view')->name('files.index');
    Route::post('/files/mkdir', [FilesController::class, 'mkdir'])
        ->middleware('perm:files.manage')->name('files.mkdir');
    Route::post('/files/write', [FilesController::class, 'write'])
        ->middleware('perm:files.manage')->name('files.write');
    Route::post('/files/rename', [FilesController::class, 'rename'])
        ->middleware('perm:files.manage')->name('files.rename');
    Route::post('/files/delete', [FilesController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('files.destroy');

    Route::get('/disk', [DiskUsageController::class, 'index'])
        ->middleware('perm:files.view')->name('disk.index');

    Route::get('/email', [MailController::class, 'index'])
        ->middleware('perm:email.view')->name('email.index');
    Route::post('/email', [MailController::class, 'store'])
        ->middleware('perm:email.manage')->name('email.store');
    Route::delete('/email/{mailbox}', [MailController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('email.destroy');

    Route::get('/forwarders', [ForwardersController::class, 'index'])
        ->middleware('perm:email.view')->name('forwarders.index');
    Route::post('/forwarders', [ForwardersController::class, 'store'])
        ->middleware('perm:email.manage')->name('forwarders.store');
    Route::delete('/forwarders/{forwarder}', [ForwardersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('forwarders.destroy');

    Route::get('/autoresponders', [AutorespondersController::class, 'index'])
        ->middleware('perm:email.view')->name('autoresponders.index');
    Route::post('/autoresponders', [AutorespondersController::class, 'store'])
        ->middleware('perm:email.manage')->name('autoresponders.store');
    Route::delete('/autoresponders/{autoresponder}', [AutorespondersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('autoresponders.destroy');

    Route::get('/default-address', [DefaultAddressController::class, 'index'])
        ->middleware('perm:email.view')->name('default-address.index');
    Route::post('/default-address', [DefaultAddressController::class, 'store'])
        ->middleware('perm:email.manage')->name('default-address.store');
    Route::delete('/default-address/{catchall}', [DefaultAddressController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('default-address.destroy');

    Route::get('/email-filters', [EmailFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('email-filters.index');
    Route::post('/email-filters', [EmailFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('email-filters.store');
    Route::delete('/email-filters/{filter}', [EmailFiltersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('email-filters.destroy');

    Route::get('/deliverability', [DeliverabilityController::class, 'index'])
        ->middleware('perm:email.view')->name('deliverability.index');
    Route::post('/deliverability', [DeliverabilityController::class, 'store'])
        ->middleware('perm:email.manage')->name('deliverability.store');

    Route::get('/spam-filters', [SpamFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('spam-filters.index');
    Route::post('/spam-filters', [SpamFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('spam-filters.store');

    Route::get('/mailing-lists', [MailingListsController::class, 'index'])
        ->middleware('perm:email.view')->name('mailing-lists.index');
    Route::post('/mailing-lists', [MailingListsController::class, 'store'])
        ->middleware('perm:email.manage')->name('mailing-lists.store');
    Route::delete('/mailing-lists/{mailing_list}', [MailingListsController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('mailing-lists.destroy');

    Route::get('/email-routing', [EmailRoutingController::class, 'index'])
        ->middleware('perm:email.view')->name('email-routing.index');
    Route::post('/email-routing', [EmailRoutingController::class, 'store'])
        ->middleware('perm:email.manage')->name('email-routing.store');

    Route::get('/track-delivery', [TrackDeliveryController::class, 'index'])
        ->middleware('perm:email.view')->name('track-delivery.index');
    Route::post('/track-delivery', [TrackDeliveryController::class, 'store'])
        ->middleware('perm:email.manage')->name('track-delivery.store');

    Route::get('/global-filters', [GlobalFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('global-filters.index');
    Route::post('/global-filters', [GlobalFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('global-filters.store');
    Route::delete('/global-filters/{global_filter}', [GlobalFiltersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('global-filters.destroy');

    Route::get('/address-importer', [AddressImporterController::class, 'index'])
        ->middleware('perm:email.view')->name('address-importer.index');
    Route::post('/address-importer', [AddressImporterController::class, 'store'])
        ->middleware('perm:email.manage')->name('address-importer.store');

    Route::get('/encryption', [EncryptionController::class, 'index'])
        ->middleware('perm:email.view')->name('encryption.index');
    Route::post('/encryption', [EncryptionController::class, 'store'])
        ->middleware('perm:email.manage')->name('encryption.store');
    Route::delete('/encryption/{encryption_key}', [EncryptionController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('encryption.destroy');

    Route::get('/boxtrapper', [BoxTrapperController::class, 'index'])
        ->middleware('perm:email.view')->name('boxtrapper.index');
    Route::post('/boxtrapper', [BoxTrapperController::class, 'store'])
        ->middleware('perm:email.manage')->name('boxtrapper.store');

    Route::get('/calendar', [CalendarController::class, 'index'])
        ->middleware('perm:email.view')->name('calendar.index');
    Route::post('/calendar', [CalendarController::class, 'store'])
        ->middleware('perm:email.manage')->name('calendar.store');
    Route::delete('/calendar/{calendar_item}', [CalendarController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('calendar.destroy');

    Route::get('/email-disk', [EmailDiskUsageController::class, 'index'])
        ->middleware('perm:email.view')->name('email-disk.index');

    Route::get('/webmail', [WebmailController::class, 'index'])
        ->middleware('perm:email.view')->name('webmail.index');
    Route::post('/webmail', [WebmailController::class, 'store'])
        ->middleware('perm:email.manage')->name('webmail.store');

    Route::get('/mysql', [MysqlDatabasesController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql.index');
    Route::post('/mysql', [MysqlDatabasesController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql.store');
    Route::delete('/mysql/{mysql_database}', [MysqlDatabasesController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('mysql.destroy');

    Route::get('/mysql-wizard', [MysqlWizardController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql-wizard.index');
    Route::post('/mysql-wizard', [MysqlWizardController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql-wizard.store');

    Route::get('/phpmyadmin', [PhpmyadminController::class, 'index'])
        ->middleware('perm:databases.view')->name('phpmyadmin.index');
    Route::post('/phpmyadmin', [PhpmyadminController::class, 'store'])
        ->middleware('perm:databases.manage')->name('phpmyadmin.store');

    Route::get('/remote-mysql', [RemoteMysqlController::class, 'index'])
        ->middleware('perm:databases.view')->name('remote-mysql.index');
    Route::post('/remote-mysql', [RemoteMysqlController::class, 'store'])
        ->middleware('perm:databases.manage')->name('remote-mysql.store');
    Route::delete('/remote-mysql/{mysql_remote_host}', [RemoteMysqlController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('remote-mysql.destroy');

    Route::get('/zone-editor', [ZoneEditorController::class, 'index'])
        ->middleware('perm:dns.view')->name('zone-editor.index');
    Route::post('/zone-editor', [ZoneEditorController::class, 'store'])
        ->middleware('perm:dns.manage')->name('zone-editor.store');
    Route::delete('/zone-editor/{dns_record}', [ZoneEditorController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('zone-editor.destroy');

    Route::get('/dynamic-dns', [DynamicDnsController::class, 'index'])
        ->middleware('perm:dns.view')->name('dynamic-dns.index');
    Route::post('/dynamic-dns', [DynamicDnsController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.store');
    Route::delete('/dynamic-dns/{dns_dynamic_host}', [DynamicDnsController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.destroy');

    Route::get('/ssh', [SshController::class, 'index'])
        ->middleware('perm:ssh.view')->name('ssh.index');
    Route::post('/ssh', [SshController::class, 'store'])
        ->middleware('perm:ssh.manage')->name('ssh.store');
    Route::post('/ssh/delete', [SshController::class, 'destroy'])
        ->middleware('perm:ssh.manage')->name('ssh.destroy');
    Route::post('/ssh/shell', [SshController::class, 'shell'])
        ->middleware('perm:ssh.manage')->name('ssh.shell');

    Route::get('/privacy', [PrivacyController::class, 'index'])
        ->middleware('perm:privacy.view')->name('privacy.index');
    Route::post('/privacy', [PrivacyController::class, 'store'])
        ->middleware('perm:privacy.manage')->name('privacy.store');
    Route::post('/privacy/delete', [PrivacyController::class, 'destroy'])
        ->middleware('perm:privacy.manage')->name('privacy.destroy');

    Route::get('/cron', [CronController::class, 'index'])
        ->middleware('perm:cron.view')->name('cron.index');
    Route::post('/cron', [CronController::class, 'store'])
        ->middleware('perm:cron.manage')->name('cron.store');
    Route::delete('/cron/{cron}', [CronController::class, 'destroy'])
        ->middleware('perm:cron.manage')->name('cron.destroy');

    Route::get('/ssl', [SslController::class, 'index'])
        ->middleware('perm:ssl.view')->name('ssl.index');
    Route::post('/ssl/autossl', [SslController::class, 'autossl'])
        ->middleware('perm:ssl.manage')->name('ssl.autossl');
    Route::post('/ssl/{domain}', [SslController::class, 'issue'])
        ->middleware('perm:ssl.manage')->name('ssl.issue');
    Route::post('/ssl/{domain}/autossl', [SslController::class, 'toggle'])
        ->middleware('perm:ssl.manage')->name('ssl.toggle');
    Route::delete('/ssl/{domain}', [SslController::class, 'destroy'])
        ->middleware('perm:ssl.manage')->name('ssl.destroy');

    // -- Security (always available to the logged-in user) -------------------
    Route::prefix('security')->name('security.')->group(function (): void {
        Route::get('/', [SecurityController::class, 'index'])->name('index');
        Route::post('/2fa/start', [SecurityController::class, 'startTwoFactor'])->name('2fa.start');
        Route::post('/2fa/confirm', [SecurityController::class, 'confirmTwoFactor'])->name('2fa.confirm');
        Route::post('/2fa/disable', [SecurityController::class, 'disableTwoFactor'])->name('2fa.disable');
        Route::get('/password', [SecurityController::class, 'password'])->name('password');
        Route::post('/password', [SecurityController::class, 'updatePassword'])->name('password.update');
        Route::get('/sessions', [SecurityController::class, 'sessions'])->name('sessions');
        Route::delete('/sessions/{id}', [SecurityController::class, 'destroySession'])->name('sessions.destroy');
    });

    // -- Hosting accounts (Step 3). /create MUST sit before /{account}.
    Route::get('/accounts', [AccountsController::class, 'index'])
        ->middleware('perm:accounts.view')->name('accounts.index');
    Route::get('/accounts/create', [AccountsController::class, 'create'])
        ->middleware('perm:accounts.create')->name('accounts.create');
    Route::post('/accounts', [AccountsController::class, 'store'])
        ->middleware('perm:accounts.create')->name('accounts.store');
    Route::get('/accounts/{account}', [AccountsController::class, 'show'])
        ->middleware('perm:accounts.view')->name('accounts.show');
    Route::post('/accounts/{account}/suspend', [AccountsController::class, 'suspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.suspend');
    Route::post('/accounts/{account}/unsuspend', [AccountsController::class, 'unsuspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.unsuspend');
    Route::post('/accounts/{account}/terminate', [AccountsController::class, 'terminate'])
        ->middleware('perm:accounts.terminate')->name('accounts.terminate');
    Route::post('/accounts/{account}/upgrade', [AccountsController::class, 'upgrade'])
        ->middleware('perm:accounts.modify')->name('accounts.upgrade');
    Route::post('/accounts/{account}/quota', [AccountsController::class, 'quota'])
        ->middleware('perm:accounts.modify')->name('accounts.quota');
    Route::post('/accounts/{account}/php', [AccountsController::class, 'php'])
        ->middleware('perm:accounts.modify')->name('accounts.php');

    Route::get('/packages', [PackagesController::class, 'index'])
        ->middleware('perm:packages.view')->name('packages.index');
    Route::middleware('perm:packages.manage')->group(function (): void {
        Route::get('/packages/create', [PackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [PackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [PackagesController::class, 'edit'])->name('packages.edit');
        Route::put('/packages/{package}', [PackagesController::class, 'update'])->name('packages.update');
        Route::post('/packages/{package}/archive', [PackagesController::class, 'archive'])->name('packages.archive');
    });

    // -- Users (panel logins) -------------------------------------------------
    Route::middleware('perm:users.view')->group(function (): void {
        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    });
    Route::middleware('perm:users.manage')->group(function (): void {
        Route::get('/users/create', [UsersController::class, 'create'])->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/password', [UsersController::class, 'resetPassword'])->name('users.password');
    });

    // -- Audit -----------------------------------------------------------------
    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('perm:audit.view')->name('audit.index');

    // -- License / trial (admin) -----------------------------------------------
    Route::prefix('license')->name('license.')->middleware('perm:license.view')->group(function (): void {
        Route::get('/', [LicenseController::class, 'index'])->name('index');
        Route::post('/activate', [LicenseController::class, 'activate'])
            ->middleware('perm:license.manage')->name('activate');
    });

    // -- Server (admin) ----------------------------------------------------------
    Route::prefix('system')->name('system.')->middleware('perm:system.view')->group(function (): void {
        Route::get('/', [SystemController::class, 'index'])->name('index');
        Route::get('/services', [SystemController::class, 'services'])->name('services');
        Route::get('/tasks', [SystemController::class, 'tasks'])->name('tasks');
        Route::post('/tasks/run', [SystemController::class, 'runTask'])
            ->middleware('perm:system.manage')->name('tasks.run');
    });
});

// Fallback: unknown panel URLs get a clean 404 page, not a stack trace.
Route::fallback(fn () => response()->view('errors.404', [], 404));
