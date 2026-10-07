<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** WHM DNS Cluster — ek remote DNS/nameserver node. */
final class DnsClusterNode extends Model
{
    protected $table = 'dns_cluster_nodes';

    protected $fillable = ['hostname', 'ip', 'role', 'status', 'last_synced_at'];

    protected $casts = ['last_synced_at' => 'datetime'];
}
