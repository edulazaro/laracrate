<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A tenant's dedicated bucket that overrides ONE disk of the global config.
 *
 * Typical SaaS model (R2/S3 single SaaS account, per-tenant buckets):
 *   only `bucket` and optionally `public_url` are overridden. The rest
 *   (key, secret, endpoint, region, driver) is inherited from `base_disk`.
 *
 * BYOA model (enterprise client with their own account):
 *   `credentials` encrypted with APP_KEY contains the overrides for
 *   key/secret/endpoint/region/driver (whatever the client provides).
 */
class TenantBucket extends Model
{
    protected $table = 'laracrate_tenant_buckets';

    protected $fillable = [
        'tenant_type',
        'tenant_id',
        'base_disk',
        'bucket',
        'public_url',
        'credentials',
        'is_active',
        'label',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active'   => 'boolean',
    ];

    /** The tenant that owns this bucket. */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Builds the config array for `Storage::build(...)`. Cascading merge:
     * config base + bucket override + optional BYOA.
     */
    public function toDiskConfig(): array
    {
        $base = config("filesystems.disks.{$this->base_disk}", []);

        $merged = array_merge($base, ['bucket' => $this->bucket]);

        if ($this->public_url) {
            $merged['url'] = $this->public_url;
        }

        // BYOA: overrides key/secret/endpoint/region/driver from the base.
        if (!empty($this->credentials)) {
            $merged = array_merge($merged, $this->credentials);
        }

        return $merged;
    }
}
