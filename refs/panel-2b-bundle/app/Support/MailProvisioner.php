<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\Autoresponder;
use App\Models\Catchall;
use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Models\EmailRoute;
use App\Models\MailingList;
use App\Models\SpamSetting;

final class MailProvisioner
{
    public static function enqueue(Account $account): int
    {
        $boxes = $account->mailboxes()->orderBy('id')->get()->map(static fn (Mailbox $box): array => [
            'local' => $box->localpart,
            'domain' => $box->domain,
            'hash' => $box->password_hash,
            'quota_mb' => (int) $box->quota_mb,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'mail.set', [
            'username' => $account->username,
            'mailboxes' => $boxes,
        ]);
    }

    public static function limitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXPOP ?? -1);
        if ($max < 0) {
            return false;
        }

        return $account->mailboxes()->count() >= $max;
    }

    /** @return list<string> */
    public static function domainsFor(Account $account): array
    {
        $out = [];
        if (is_string($account->main_domain) && $account->main_domain !== '') {
            $out[$account->main_domain] = true;
        }
        foreach ($account->domains ?? [] as $row) {
            if (is_string($row->domain) && $row->domain !== '') {
                $out[$row->domain] = true;
            }
        }

        return array_keys($out);
    }

    public static function enqueueForwards(Account $account): int
    {
        $rows = $account->forwarders()->orderBy('id')->get()->map(static fn (Forwarder $row): array => [
            'local' => $row->localpart,
            'domain' => $row->domain,
            'dest' => $row->dest,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'mail.forward', [
            'username' => $account->username,
            'forwards' => $rows,
        ]);
    }

    public static function fwdLimitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXFWD ?? -1);
        if ($max < 0) {
            return false;
        }

        return $account->forwarders()->count() >= $max;
    }

    public static function enqueueResponders(Account $account): int
    {
        $rows = $account->autoresponders()->orderBy('id')->get()->map(static fn (Autoresponder $row): array => [
            'local' => $row->localpart,
            'domain' => $row->domain,
            'subject' => $row->subject,
            'body' => $row->body,
            'interval_h' => (int) $row->interval_h,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'mail.autorespond', [
            'username' => $account->username,
            'responders' => $rows,
        ]);
    }

    public static function respLimitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXRESP ?? -1);
        if ($max < 0) {
            return false;
        }

        return $account->autoresponders()->count() >= $max;
    }

    public static function enqueueCatchalls(Account $account): int
    {
        $rows = $account->catchalls()->orderBy('id')->get()->map(static fn (Catchall $row): array => [
            'domain' => $row->domain,
            'dest' => $row->dest,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'mail.catchall', [
            'username' => $account->username,
            'catchalls' => $rows,
        ]);
    }

    public static function enqueueFilters(Account $account): int
    {
        $rows = $account->mailFilters()->orderBy('id')->get()->map(static fn (MailFilter $row): array => [
            'local' => $row->localpart,
            'domain' => $row->domain,
            'field' => $row->field,
            'needle' => $row->needle,
            'action' => $row->action,
            'folder' => (string) $row->folder,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'mail.filter', [
            'username' => $account->username,
            'filters' => $rows,
        ]);
    }

    public static function filterLimitReached(Account $account): bool
    {
        return $account->mailFilters()->count() >= 50;
    }

    /** @param list<string> $domains */
    public static function enqueueDeliverability(Account $account, array $domains): int
    {
        return AccountProvisioner::enqueue($account, 'mail.deliverability', [
            'username' => $account->username,
            'domains' => array_values($domains),
        ]);
    }

    public static function enqueueSpam(Account $account): int
    {
        $row = SpamSetting::query()->where('account_id', $account->id)->first();
        $black = is_array($row?->blacklist) ? $row->blacklist : [];
        $white = is_array($row?->whitelist) ? $row->whitelist : [];

        return AccountProvisioner::enqueue($account, 'mail.spam', [
            'username' => $account->username,
            'required_score' => (int) ($row?->required_score ?? 5),
            'blacklist' => array_values($black),
            'whitelist' => array_values($white),
        ]);
    }

    public static function enqueueLists(Account $account): int
    {
        $rows = $account->mailingLists()->orderBy('id')->get()->map(static fn (MailingList $row): array => [
            'local' => $row->localpart,
            'domain' => $row->domain,
            'owner' => $row->owner,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'mail.list', [
            'username' => $account->username,
            'lists' => $rows,
        ]);
    }

    public static function listLimitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXLST ?? -1);
        if ($max < 0) {
            return false;
        }

        return $account->mailingLists()->count() >= $max;
    }

    public static function enqueueRouting(Account $account): int
    {
        $rows = $account->emailRoutes()->orderBy('id')->get()->map(static fn (EmailRoute $row): array => [
            'domain' => $row->domain,
            'mode' => $row->mode,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'mail.routing', [
            'username' => $account->username,
            'routes' => $rows,
        ]);
    }

    public static function routingLimitReached(Account $account): bool
    {
        return $account->emailRoutes()->count() >= 50;
    }
}
