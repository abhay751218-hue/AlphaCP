<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

/** Panel side of extra domains. Privileged Apache work is paneld (domain.add/remove). */
final class DomainProvisioner
{
    /** @var array<string, string> */
    public const LIMIT_KEY = [
        'addon' => 'MAXADDON',
        'sub' => 'MAXSUB',
        'parked' => 'MAXPARK',
        'redirect' => 'MAXPARK',
    ];

    public static function seedMain(Account $account): Domain
    {
        return Domain::query()->firstOrCreate(
            ['domain' => strtolower($account->main_domain)],
            [
                'account_id' => $account->id,
                'type' => 'main',
                'document_root' => rtrim($account->home_path, '/') . '/public_html',
                'php_version' => $account->php_version,
                'status' => $account->status === 'active' ? 'active' : 'pending',
            ],
        );
    }

    public static function docrootFor(Account $account, string $type, string $domain): string
    {
        $home = rtrim($account->home_path, '/');
        $domain = strtolower($domain);
        return match ($type) {
            'addon' => $home . '/' . $domain . '/public_html',
            'sub' => $home . '/public_html/' . self::subLabel($account->main_domain, $domain),
            default => $home . '/public_html',
        };
    }

    public static function subLabel(string $main, string $fqdn): string
    {
        $main = strtolower($main);
        $fqdn = strtolower($fqdn);
        $suffix = '.' . $main;
        if (str_ends_with($fqdn, $suffix)) {
            $label = substr($fqdn, 0, -strlen($suffix));
            $label = str_replace('.', '-', $label);
            return $label !== '' ? $label : 'sub';
        }
        return explode('.', $fqdn)[0] ?: 'sub';
    }

    public static function limitReached(Account $account, string $type): bool
    {
        $key = self::LIMIT_KEY[$type] ?? null;
        if ($key === null) {
            return false;
        }
        $package = $account->package instanceof Package ? $account->package : $account->package()->first();
        $max = (int) ($package?->{$key} ?? -1);
        if ($max < 0) {
            return false;
        }
        $count = $account->domains()->where('type', $type)->count();
        return $count >= $max;
    }

    public static function featureAllowed(Account $account): bool
    {
        $features = $account->package?->featureList?->features;
        if (! is_array($features)) {
            return true;
        }
        return ($features['domains'] ?? true) !== false;
    }

    public static function refresh(Domain $domain): void
    {
        $task = DB::table('tasks')
            ->where('account_id', $domain->account_id)
            ->whereIn('type', ['domain.add', 'domain.remove'])
            ->orderByDesc('id')
            ->first();
        if ($task === null) {
            return;
        }
        $status = (string) $task->status;
        $type = (string) $task->type;
        if ($status === 'failed') {
            if ($type === 'domain.add' && $domain->status === 'pending') {
                $domain->forceFill(['status' => 'failed'])->save();
            }
            return;
        }
        if ($status !== 'success') {
            return;
        }
        if ($type === 'domain.add') {
            $domain->forceFill(['status' => 'active'])->save();
        }
        if ($type === 'domain.remove' && $domain->status === 'removing') {
            $domain->delete();
        }
    }
}
