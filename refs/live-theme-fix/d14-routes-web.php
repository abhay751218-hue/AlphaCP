<?php

declare(strict_types=1);

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\PackagesController;
use App\Http\Controllers\Auth\EntryLoginController as LoginController; // ACP-ENTRY-GATE
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
use App\Http\Controllers\MysqlUsersController;
use App\Http\Controllers\MysqlWizardController;
use App\Http\Controllers\PhpmyadminController;
use App\Http\Controllers\RemoteMysqlController;
use App\Http\Controllers\ZoneEditorController;
use App\Http\Controllers\DynamicDnsController;
use App\Http\Controllers\TrackDnsController;
use App\Http\Controllers\DnsZonesController;
use App\Http\Controllers\HostnameAController;
use App\Http\Controllers\ZoneTemplatesController;
use App\Http\Controllers\GlobalEmailRoutingController;
use App\Http\Controllers\NsReportController;
use App\Http\Controllers\ParkDomainController;
use App\Http\Controllers\DnsCleanupController;
use App\Http\Controllers\ZoneTtlController;
use App\Http\Controllers\DomainForwardController;
use App\Http\Controllers\DnsSyncController;
use App\Http\Controllers\BackupConfigController;
use App\Http\Controllers\BackupDestinationController;
use App\Http\Controllers\BackupRestorationController;
use App\Http\Controllers\BackupUserSelectionController;
use App\Http\Controllers\FileDirectoryRestorationController;
use App\Http\Controllers\TransferRestoreController;
use App\Http\Controllers\TransferToolController;
use App\Http\Controllers\TransferReviewController;
use App\Http\Controllers\NameserverSelectionController;
use App\Http\Controllers\MailingListsController;
use App\Http\Controllers\SpamFiltersController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\IndexesController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BackupWizardController;
use App\Http\Controllers\FileRestorationController;
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
// Panel-internal (server-to-server, shared secret): Roundcube SSO verify.
Route::get('/internal/webmail-sso', [App\Http\Controllers\WebmailSsoController::class, 'verify'])
    ->middleware('throttle:60,1')->name('internal.webmail-sso');

Route::middleware('guest')->group(function (): void {
    Route::get('/', [LoginController::class, 'show'])->name('login');
    // /login bhi wahi login page — bookmark/WHMCS/cPanel aadat. Pehle 404 deta tha.
    Route::get('/login', [LoginController::class, 'show'])->name('login.page');
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

    Route::get('/backup', [BackupController::class, 'index'])
        ->middleware('perm:files.view')->name('backup.index');
    Route::post('/backup', [BackupController::class, 'store'])
        ->middleware('perm:files.manage')->name('backup.store');
    Route::post('/backup/archive', [BackupController::class, 'archive'])
        ->middleware('perm:files.manage')->name('backup.archive');
    Route::get('/backup/archive/{archiveId}/download', [BackupController::class, 'download'])
        ->middleware('perm:files.view')->name('backup.archive-download');
    Route::post('/backup/restore', [BackupController::class, 'restore'])
        ->middleware('perm:files.manage')->name('backup.restore-archive');

    Route::get('/backup-wizard', [BackupWizardController::class, 'index'])
        ->middleware('perm:files.view')->name('backup-wizard.index');
    Route::post('/backup-wizard', [BackupWizardController::class, 'store'])
        ->middleware('perm:files.manage')->name('backup-wizard.store');

    Route::get('/file-restoration', [FileRestorationController::class, 'index'])
        ->middleware('perm:files.view')->name('file-restoration.index');
    Route::post('/file-restoration', [FileRestorationController::class, 'store'])
        ->middleware('perm:files.manage')->name('file-restoration.store');

    Route::get('/email', [MailController::class, 'index'])
        ->middleware('perm:email.view')->name('email.index');
    Route::post('/email', [MailController::class, 'store'])
        ->middleware('perm:email.manage')->name('email.store');
    Route::delete('/email/{mailbox}', [MailController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('email.destroy');
    Route::put('/email/{mailbox}', [MailController::class, 'update'])
        ->middleware('perm:email.manage')->name('email.update');

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
    Route::patch('/mailing-lists/{mailing_list}', [MailingListsController::class, 'update'])
        ->middleware('perm:email.manage')->name('mailing-lists.update');
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
    Route::post('/webmail/open', [WebmailController::class, 'open'])
        ->middleware('perm:email.view')->name('webmail.open');

    Route::get('/mysql', [MysqlDatabasesController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql.index');
    Route::post('/mysql', [MysqlDatabasesController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql.store');
    Route::delete('/mysql/{mysql_database}', [MysqlDatabasesController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('mysql.destroy');

    Route::get('/mysql-users', [MysqlUsersController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql-users.index');
    Route::post('/mysql-users', [MysqlUsersController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql-users.store');
    Route::post('/mysql-users/grant', [MysqlUsersController::class, 'grant'])
        ->middleware('perm:databases.manage')->name('mysql-users.grant');
    Route::post('/mysql-users/{mysql_user}/password', [MysqlUsersController::class, 'password'])
        ->middleware('perm:databases.manage')->name('mysql-users.password');
    Route::delete('/mysql-users/{mysql_user}', [MysqlUsersController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('mysql-users.destroy');

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
    Route::put('/zone-editor/{dns_record}', [ZoneEditorController::class, 'update'])
        ->middleware('perm:dns.manage')->name('zone-editor.update');

    Route::get('/dynamic-dns', [DynamicDnsController::class, 'index'])
        ->middleware('perm:dns.view')->name('dynamic-dns.index');
    Route::post('/dynamic-dns', [DynamicDnsController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.store');
    Route::delete('/dynamic-dns/{dns_dynamic_host}', [DynamicDnsController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.destroy');

    Route::get('/track-dns', [TrackDnsController::class, 'index'])
        ->middleware('perm:dns.view')->name('track-dns.index');
    Route::post('/track-dns', [TrackDnsController::class, 'store'])
        ->middleware('perm:dns.view')->name('track-dns.store');

    Route::get('/dns-zones', [DnsZonesController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-zones.index');
    Route::post('/dns-zones', [DnsZonesController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-zones.store');
    Route::delete('/dns-zones', [DnsZonesController::class, 'destroy'])
        ->middleware('perm:accounts.view')->name('dns-zones.destroy');
    Route::post('/dns-zones/{account}/sync', [DnsZonesController::class, 'sync'])
        ->middleware('perm:accounts.view')->name('dns-zones.sync');

    Route::get('/hostname-a', [HostnameAController::class, 'index'])
        ->middleware('perm:accounts.view')->name('hostname-a.index');
    Route::post('/hostname-a', [HostnameAController::class, 'store'])
        ->middleware('perm:accounts.view')->name('hostname-a.store');

    Route::get('/zone-templates', [ZoneTemplatesController::class, 'index'])
        ->middleware('perm:accounts.view')->name('zone-templates.index');
    Route::post('/zone-templates', [ZoneTemplatesController::class, 'store'])
        ->middleware('perm:accounts.view')->name('zone-templates.store');
    Route::delete('/zone-templates/{dns_template}', [ZoneTemplatesController::class, 'destroy'])
        ->middleware('perm:accounts.view')->name('zone-templates.destroy');

    Route::get('/global-email-routing', [GlobalEmailRoutingController::class, 'index'])
        ->middleware('perm:accounts.view')->name('global-email-routing.index');
    Route::post('/global-email-routing', [GlobalEmailRoutingController::class, 'store'])
        ->middleware('perm:accounts.view')->name('global-email-routing.store');

    Route::get('/ns-report', [NsReportController::class, 'index'])
        ->middleware('perm:accounts.view')->name('ns-report.index');
    Route::post('/ns-report', [NsReportController::class, 'store'])
        ->middleware('perm:accounts.view')->name('ns-report.store');

    Route::get('/park-domain', [ParkDomainController::class, 'index'])
        ->middleware('perm:accounts.view')->name('park-domain.index');
    Route::post('/park-domain', [ParkDomainController::class, 'store'])
        ->middleware('perm:accounts.view')->name('park-domain.store');

    Route::get('/dns-cleanup', [DnsCleanupController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-cleanup.index');
    Route::post('/dns-cleanup', [DnsCleanupController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-cleanup.store');

    Route::get('/zone-ttl', [ZoneTtlController::class, 'index'])
        ->middleware('perm:accounts.view')->name('zone-ttl.index');
    Route::post('/zone-ttl', [ZoneTtlController::class, 'store'])
        ->middleware('perm:accounts.view')->name('zone-ttl.store');

    Route::get('/domain-forward', [DomainForwardController::class, 'index'])
        ->middleware('perm:accounts.view')->name('domain-forward.index');
    Route::post('/domain-forward', [DomainForwardController::class, 'store'])
        ->middleware('perm:accounts.view')->name('domain-forward.store');

    Route::get('/dns-sync', [DnsSyncController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-sync.index');
    Route::post('/dns-sync', [DnsSyncController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-sync.store');

    Route::get('/nameserver-selection', [NameserverSelectionController::class, 'index'])
        ->middleware('perm:accounts.view')->name('nameserver-selection.index');
    Route::post('/nameserver-selection', [NameserverSelectionController::class, 'store'])
        ->middleware('perm:accounts.view')->name('nameserver-selection.store');

    Route::get('/backup-config', [BackupConfigController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-config.index');
    Route::post('/backup-config', [BackupConfigController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-config.store');

    Route::get('/backup-destinations', [BackupDestinationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-destinations.index');
    Route::post('/backup-destinations', [BackupDestinationController::class, 'store'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.store');
    Route::post('/backup-destinations/test', [BackupDestinationController::class, 'test'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.test');
    Route::post('/backup-destinations/push', [BackupDestinationController::class, 'push'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.push');
    Route::post('/backup-destinations/browse', [BackupDestinationController::class, 'browse'])
        ->middleware('perm:accounts.view')->name('backup-destinations.browse');
    Route::delete('/backup-destinations/{name}', [BackupDestinationController::class, 'destroy'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.destroy');

    Route::get('/backup-restoration', [BackupRestorationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-restoration.index');
    Route::post('/backup-restoration', [BackupRestorationController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-restoration.store');

    Route::get('/backup-user-selection', [BackupUserSelectionController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-user-selection.index');
    Route::post('/backup-user-selection', [BackupUserSelectionController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-user-selection.store');

    Route::get('/file-directory-restoration', [FileDirectoryRestorationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('file-directory-restoration.index');
    Route::post('/file-directory-restoration', [FileDirectoryRestorationController::class, 'store'])
        ->middleware('perm:accounts.view')->name('file-directory-restoration.store');

    Route::get('/transfer-tool', [TransferToolController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-tool.index');
    Route::post('/transfer-tool', [TransferToolController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-tool.store');
    // S10 remote pull: 1) host key fingerprint lao (probe) 2) archive lao (pull)
    Route::post('/transfer-tool/probe', [TransferToolController::class, 'probe'])
        ->middleware('perm:accounts.view')->name('transfer-tool.probe');
    Route::post('/transfer-tool/pull', [TransferToolController::class, 'pull'])
        ->middleware('perm:accounts.view')->name('transfer-tool.pull');

    Route::get('/transfer-restore', [TransferRestoreController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-restore.index');
    Route::post('/transfer-restore', [TransferRestoreController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-restore.store');

    Route::get('/transfer-review', [TransferReviewController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-review.index');
    Route::post('/transfer-review', [TransferReviewController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-review.store');

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
// ---- FTP Accounts (portable feature: pure-ftpd) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ftp', [\App\Http\Controllers\FtpController::class, 'index'])
        ->middleware('perm:files.view')->name('ftp.index');
    Route::post('/ftp', [\App\Http\Controllers\FtpController::class, 'store'])
        ->middleware('perm:files.manage')->name('ftp.store');
    Route::post('/ftp/{ftpAccount}/password', [\App\Http\Controllers\FtpController::class, 'password'])
        ->middleware('perm:files.manage')->name('ftp.password');
    Route::delete('/ftp/{ftpAccount}', [\App\Http\Controllers\FtpController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('ftp.destroy');
});
// ---- /FTP ----
// ---- Metrics (portable feature: access-log stats) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/metrics', [\App\Http\Controllers\MetricsController::class, 'index'])
        ->middleware('perm:metrics.view')->name('metrics.index');
});
// ---- /Metrics ----
// ---- IP Blocker (portable feature: ufw/iptables deny) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'index'])
        ->middleware('perm:security.view')->name('ip-blocker.index');
    Route::post('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'store'])
        ->middleware('perm:security.view')->name('ip-blocker.store');
    Route::delete('/ip-blocker/{blockedIp}', [\App\Http\Controllers\IpBlockerController::class, 'destroy'])
        ->middleware('perm:security.view')->name('ip-blocker.destroy');
});
// ---- /IP Blocker ----
// ---- Security Tools (ModSecurity WAF + Virus Scanner) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/security-tools', [\App\Http\Controllers\SecurityToolsController::class, 'index'])
        ->middleware('perm:security.view')->name('security-tools.index');
    Route::post('/security-tools/modsec', [\App\Http\Controllers\SecurityToolsController::class, 'toggleModsec'])
        ->middleware('perm:security.view')->name('security-tools.modsec');
    Route::post('/security-tools/scan', [\App\Http\Controllers\SecurityToolsController::class, 'scan'])
        ->middleware('perm:security.view')->name('security-tools.scan');
});
// ---- /Security Tools ----
// ---- Site Software / App Installer (WordPress one-click) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/apps', [\App\Http\Controllers\AppsController::class, 'index'])
        ->middleware('perm:software.view')->name('apps.index');
    Route::post('/apps', [\App\Http\Controllers\AppsController::class, 'store'])
        ->middleware('perm:software.manage')->name('apps.store');
});
// ---- /Site Software ----
// ---- Monitoring / Resource Usage ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/monitoring', [\App\Http\Controllers\MonitoringController::class, 'index'])
        ->middleware('perm:metrics.view')->name('monitoring.index');
});
// ---- /Monitoring ----
// ---- D7: Metrics tools — Errors / Raw Access / Awstats / Network Tools ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/errors-log', [\App\Http\Controllers\ErrorsLogController::class, 'index'])
        ->middleware('perm:metrics.view')->name('errors-log.index');
    Route::get('/raw-access', [\App\Http\Controllers\RawAccessController::class, 'index'])
        ->middleware('perm:metrics.view')->name('raw-access.index');
    Route::get('/awstats', [\App\Http\Controllers\AwstatsController::class, 'index'])
        ->middleware('perm:metrics.view')->name('awstats.index');
    Route::get('/network-tools', [\App\Http\Controllers\NetworkToolsController::class, 'index'])
        ->middleware('perm:metrics.view')->name('network-tools.index');
    Route::post('/network-tools', [\App\Http\Controllers\NetworkToolsController::class, 'lookup'])
        ->middleware('perm:metrics.view')->name('network-tools.lookup');
});
// ---- /D7 Metrics tools ----
// ---- D8: Mail Queue Manager + Security Policies (WHM) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/mail-queue', [\App\Http\Controllers\MailQueueController::class, 'index'])
        ->middleware('perm:system.view')->name('mail-queue.index');
    Route::post('/mail-queue', [\App\Http\Controllers\MailQueueController::class, 'action'])
        ->middleware('perm:system.manage')->name('mail-queue.action');
    Route::get('/security-policies', [\App\Http\Controllers\SecurityPoliciesController::class, 'index'])
        ->middleware('perm:system.view')->name('security-policies.index');
});
// ---- /D8 ----
// ---- D10: Node.js Selector + PHP Composer + Updates (aakhri 3 tiles) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/nodejs', [\App\Http\Controllers\NodejsSelectorController::class, 'index'])
        ->middleware('perm:software.view')->name('nodejs.index');
    Route::get('/composer', [\App\Http\Controllers\ComposerController::class, 'index'])
        ->middleware('perm:software.view')->name('composer.index');
    Route::get('/updates', [\App\Http\Controllers\UpdatesController::class, 'index'])
        ->middleware('perm:system.view')->name('updates.index');
});
// ---- /D10 ----
// ---- D11: Restart Services (WHM) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::post('/system/services/restart', [\App\Http\Controllers\SystemController::class, 'restartService'])
        ->middleware('perm:system.manage')->name('system.services.restart');
});
// ---- /D11 ----
// ---- D12: phpMyAdmin one-click SSO ----
Route::get('/internal/pma-sso', [\App\Http\Controllers\PmaSsoController::class, 'verify'])
    ->middleware('throttle:60,1')->name('internal.pma-sso');
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::post('/phpmyadmin/open', [\App\Http\Controllers\PhpmyadminController::class, 'open'])
        ->middleware('perm:databases.view')->name('phpmyadmin.open');
});
// ---- /D12 ----
// ---- D13: Node.js App Manager (PM2-style) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::post('/nodejs/apps', [\App\Http\Controllers\NodejsSelectorController::class, 'store'])
        ->middleware('perm:software.manage')->name('nodejs.apps.store');
    Route::post('/nodejs/apps/control', [\App\Http\Controllers\NodejsSelectorController::class, 'control'])
        ->middleware('perm:software.manage')->name('nodejs.apps.control');
});
// ---- /D13 ----
// ---- D14: mailbox suspend + MX prio/AAAA + db-user revoke ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::post('/email/{mailbox}/suspend', [MailController::class, 'suspend'])
        ->middleware('perm:email.manage')->name('email.suspend');
    Route::post('/mysql-users/revoke', [MysqlUsersController::class, 'revoke'])
        ->middleware('perm:databases.manage')->name('mysql-users.revoke');
});
// ---- /D14 ----
// ---- WHM API 1 compatible (billing integration, Bearer token) ----
Route::prefix('json-api')->middleware([\App\Http\Middleware\EnsureApiToken::class])->group(function (): void {
    Route::get('/listaccts', [\App\Http\Controllers\WhmApiController::class, 'listaccts']);
    Route::get('/accountsummary', [\App\Http\Controllers\WhmApiController::class, 'accountsummary']);
    Route::post('/createacct', [\App\Http\Controllers\WhmApiController::class, 'createacct']);
    Route::get('/suspendacct', [\App\Http\Controllers\WhmApiController::class, 'suspendacct']);
    Route::get('/unsuspendacct', [\App\Http\Controllers\WhmApiController::class, 'unsuspendacct']);
    Route::get('/removeacct', [\App\Http\Controllers\WhmApiController::class, 'removeacct']);
});
// ---- /WHM API ----
// ---- Manage API Tokens (panel UI) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'index'])
        ->middleware('perm:api.view')->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'store'])
        ->middleware('perm:api.manage')->name('api-tokens.store');
    Route::delete('/api-tokens/{apiToken}', [\App\Http\Controllers\ApiTokensController::class, 'destroy'])
        ->middleware('perm:api.manage')->name('api-tokens.destroy');
});
// ---- /API Tokens ----

// ---- Reseller Center (WHM-style) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/resellers', [\App\Http\Controllers\ResellersController::class, 'index'])
        ->middleware('perm:users.view')->name('resellers.index');
    Route::post('/resellers', [\App\Http\Controllers\ResellersController::class, 'store'])
        ->middleware('perm:roles.manage')->name('resellers.store');
    Route::post('/resellers/privileges', [\App\Http\Controllers\ResellersController::class, 'updatePrivileges'])
        ->middleware('perm:roles.manage')->name('resellers.privileges');
    Route::delete('/resellers/{user}', [\App\Http\Controllers\ResellersController::class, 'destroy'])
        ->middleware('perm:roles.manage')->name('resellers.destroy');
});
// ---- /Reseller Center ----

// ---- G5: Git Version Control + Terminal ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/git', [\App\Http\Controllers\GitController::class, 'index'])
        ->middleware('perm:files.view')->name('git.index');
    Route::post('/git/clone', [\App\Http\Controllers\GitController::class, 'clone'])
        ->middleware('perm:files.manage')->name('git.clone');
    Route::get('/git/status/{dir}', [\App\Http\Controllers\GitController::class, 'status'])
        ->middleware('perm:files.view')->name('git.status');
    Route::post('/git/pull/{dir}', [\App\Http\Controllers\GitController::class, 'pull'])
        ->middleware('perm:files.manage')->name('git.pull');

    Route::get('/terminal', [\App\Http\Controllers\TerminalController::class, 'index'])
        ->middleware('perm:system.manage')->name('terminal.index');
    Route::post('/terminal', [\App\Http\Controllers\TerminalController::class, 'run'])
        ->middleware('perm:system.manage')->name('terminal.run');
});
// ---- /G5 ----

// ---- License Server (sellable signed licenses) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'index'])
        ->middleware('perm:license.manage')->name('license-server.index');
    Route::post('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'store'])
        ->middleware('perm:license.manage')->name('license-server.store');
    Route::delete('/license-server/{licenseKey}', [\App\Http\Controllers\LicenseServerController::class, 'destroy'])
        ->middleware('perm:license.manage')->name('license-server.destroy');
});
// Customer panel ka online verify (public, read-only)
Route::post('/license-server/verify', [\App\Http\Controllers\LicenseServerController::class, 'verify'])
    ->name('license-server.verify');
// ---- /License Server ----

// ---- Security extras: Hotlink + Leech Protection ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/hotlink-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'hotlink'])
        ->middleware('perm:security.view')->name('secextra.hotlink');
    Route::get('/leech-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'leech'])
        ->middleware('perm:security.view')->name('secextra.leech');
    Route::post('/security-extras', [\App\Http\Controllers\SecurityExtrasController::class, 'store'])
        ->middleware('perm:security.manage')->name('secextra.store');
});
// ---- /Security extras ----

// ---- Web Disk (WebDAV accounts) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'index'])
        ->middleware('perm:files.view')->name('webdisk.index');
    Route::post('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'store'])
        ->middleware('perm:files.manage')->name('webdisk.store');
    Route::delete('/webdisk/{login}', [\App\Http\Controllers\WebDiskController::class, 'destroy'])
        ->where('login', '[A-Za-z0-9._-]+')
        ->middleware('perm:files.manage')->name('webdisk.destroy');
});
// ---- /Web Disk ----

// ---- File extras: Images + Optimize Website + Trash ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/images', [\App\Http\Controllers\ImagesController::class, 'index'])
        ->middleware('perm:files.view')->name('images.index');
    Route::get('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'index'])
        ->middleware('perm:files.view')->name('optimize.index');
    Route::post('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'store'])
        ->middleware('perm:files.manage')->name('optimize.store');
    Route::get('/trash', [\App\Http\Controllers\TrashController::class, 'index'])
        ->middleware('perm:files.view')->name('trash.index');
    Route::delete('/trash/{file}', [\App\Http\Controllers\TrashController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('trash.destroy');
});
// ---- /File extras ----

// ---- DNS Cluster (WHM) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'index'])
        ->middleware('perm:dns.view')->name('dns-cluster.index');
    Route::post('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dns-cluster.store');
    Route::post('/dns-cluster/sync', [\App\Http\Controllers\DnsClusterController::class, 'sync'])
        ->middleware('perm:dns.manage')->name('dns-cluster.sync');
    Route::delete('/dns-cluster/{dnsClusterNode}', [\App\Http\Controllers\DnsClusterController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dns-cluster.destroy');
});
// ---- /DNS Cluster ----

// ---- Owner Ports Config ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ports', [\App\Http\Controllers\PortsController::class, 'index'])
        ->middleware('perm:system.manage')->name('ports.index');
    Route::post('/ports', [\App\Http\Controllers\PortsController::class, 'store'])
        ->middleware('perm:system.manage')->name('ports.store');
});
// ---- /Ports ----
