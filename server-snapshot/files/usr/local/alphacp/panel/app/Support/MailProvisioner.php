<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
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
}
