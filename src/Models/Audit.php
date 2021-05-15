<?php

namespace OwenIt\Auditing\Models;

use Illuminate\Database\Eloquent\Model;

class Audit extends Model implements \OwenIt\Auditing\Contracts\Audit
{
    use \OwenIt\Auditing\Audit;

    /**
     * {@inheritdoc}
     */
    protected $guarded = [];

    /**
     * {@inheritdoc}
     */
    protected $casts = [
        'OLD_VALUES'   => 'json',
        'NEW_VALUES'   => 'json',
        // Note: Please do not add 'AUDIT_ID' in here, as it will break non-integer PK models
    ];
}
