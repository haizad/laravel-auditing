<?php

namespace OwenIt\Auditing\Drivers;

use Illuminate\Support\Facades\Config;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\AuditDriver;

class Database implements AuditDriver
{
    /**
     * {@inheritdoc}
     */
    public function audit(Auditable $model): Audit
    {
        $implementation = Config::get('audit.implementation', \OwenIt\Auditing\Models\Audit::class);

        return call_user_func([$implementation, 'create'], $model->toAudit());
    }

    /**
     * {@inheritdoc}
     */
    public function prune(Auditable $model): bool
    {
        if (($threshold = $model->getAuditThreshold()) > 0) {
            $forRemoval = $model->AUDIT_TRAILS()
                ->latest()
                ->get()
                ->slice($threshold)
                ->pluck('AUDIT_TRAILS_ID');

            if (!$forRemoval->isEmpty()) {
                return $model->AUDIT_TRAILS()
                    ->whereIn('AUDIT_TRAILS_ID', $forRemoval)
                    ->delete() > 0;
            }
        }

        return false;
    }
}
