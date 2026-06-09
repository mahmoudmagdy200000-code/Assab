<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Per-company SSO configuration (FE completion request §3.2, Enterprise plan). */
class CompanySso extends Model
{
    use HasUuids;

    protected $table = 'asab_company_sso';

    protected $fillable = [
        'company_id', 'provider', 'enabled', 'metadata_url', 'metadata', 'entity_id',
        'x509cert', 'oidc_issuer', 'oidc_client_id', 'oidc_client_secret', 'default_role',
    ];

    protected $hidden = ['oidc_client_secret'];

    protected $casts = [
        'enabled' => 'boolean',
        'oidc_client_secret' => 'encrypted',
    ];
}
