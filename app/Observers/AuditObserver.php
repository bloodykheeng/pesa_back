<?php

namespace App\Observers;

use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Feeds every create, edit and delete of an audited model into the audit trail
 * (App\Support\Audit\Audit). The models are listed in AppServiceProvider::AUDITED.
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        Audit::created($model);
    }

    public function updated(Model $model): void
    {
        Audit::updated($model);
    }

    public function deleting(Model $model): void
    {
        Audit::deleting($model);
    }
}
