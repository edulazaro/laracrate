<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Bucket dedicado de un tenant que sobrescribe UN disk del config global.
 *
 * Modelo SaaS típico (R2/S3 cuenta única del SaaS, buckets por tenant):
 *   sólo `bucket` y opcionalmente `public_url` se sobrescriben. El resto
 *   (key, secret, endpoint, region, driver) lo hereda del `base_disk`.
 *
 * Modelo BYOA (cliente enterprise con su propia cuenta):
 *   `credentials` cifrado con APP_KEY contiene los overrides para
 *   key/secret/endpoint/region/driver (lo que el cliente aporte).
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

    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Construye el array de config para `Storage::build(...)`. Merge en
     * cascada: base del config + override del bucket + opcional BYOA.
     */
    public function toDiskConfig(): array
    {
        $base = config("filesystems.disks.{$this->base_disk}", []);

        $merged = array_merge($base, ['bucket' => $this->bucket]);

        if ($this->public_url) {
            $merged['url'] = $this->public_url;
        }

        // BYOA: anula key/secret/endpoint/region/driver del base.
        if (!empty($this->credentials)) {
            $merged = array_merge($merged, $this->credentials);
        }

        return $merged;
    }
}
