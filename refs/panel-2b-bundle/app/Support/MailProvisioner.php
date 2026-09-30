<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\Forwarder;
use App\Models\Mailbox;

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
}
