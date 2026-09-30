<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'server_id', 'package_id', 'reseller_id', 'owner_user_id', 'username',
        'main_domain', 'contact_email', 'home_path', 'php_version', 'quota_mb',
        'status', 'suspend_reason', 'suspended_at', 'terminated_at',
        'setup_completed_at', 'disk_used_mb', 'bw_used_mb', 'meta', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quota_mb' => 'integer',
            'disk_used_mb' => 'integer',
            'bw_used_mb' => 'integer',
            'meta' => 'array',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'setup_completed_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccountEvent::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function forwarders(): HasMany
    {
        return $this->hasMany(Forwarder::class);
    }

    public function autoresponders(): HasMany
    {
        return $this->hasMany(Autoresponder::class);
    }

    public function catchalls(): HasMany
    {
        return $this->hasMany(Catchall::class);
    }

    public function mailFilters(): HasMany
    {
        return $this->hasMany(MailFilter::class);
    }

    public function spamSetting(): HasOne
    {
        return $this->hasOne(SpamSetting::class);
    }

    public function mailingLists(): HasMany
    {
        return $this->hasMany(MailingList::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isTerminated(): bool
    {
        return $this->status === 'terminated';
    }

    /** @param array<string, mixed> $meta */
    public function recordEvent(string $event, ?string $message = null, array $meta = []): void
    {
        $this->events()->create([
            'event' => $event,
            'message' => $message,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }
}
