<?php

namespace App\Observers;

use App\Http\Controllers\Admin\UserController;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rings the staff bell when customers act in the app: an order, a package booking, a sign-up;
 * and sends a customer who signed up the welcome email.
 * Runs after the app's own controllers save, so their responses are untouched; a failure here is
 * logged and never breaks the customer's request.
 */
class StaffAlertObserver
{
    public function created(Model $model): void
    {
        try {
            match (true) {
                // Typed in by staff (entered_by): said so, and the one who typed it is not rung.
                $model instanceof Order && $model->entered_by => StaffAlert::toStaff('order', 'Order #' . $model->order_number . ' entered',
                    number_format((float) $model->charged_amount) . ' UGX for ' . (User::whereKey($model->created_by)->value('name') ?? 'a customer')
                        . ', entered by ' . (User::whereKey($model->entered_by)->value('name') ?? 'staff'),
                    '/dashboard/orders?view=' . $model->id, except: (int) $model->entered_by),
                $model instanceof Order => StaffAlert::toStaff('order', 'New order #' . $model->order_number,
                    number_format((float) $model->charged_amount) . ' UGX from ' . (User::whereKey($model->created_by)->value('name') ?? 'a customer'),
                    '/dashboard/orders?view=' . $model->id),
                $model instanceof Package && $model->entered_by => StaffAlert::toStaff('package', 'Package entered',
                    ($model->name ?: 'A package') . ' for ' . (User::whereKey($model->created_by)->value('name') ?? 'a customer')
                        . ', entered by ' . (User::whereKey($model->entered_by)->value('name') ?? 'staff'),
                    '/dashboard/packages?view=' . $model->id, except: (int) $model->entered_by),
                $model instanceof Package => StaffAlert::toStaff('package', 'New package booking',
                    ($model->name ?: 'A package') . ': ' . ($model->pickup ?: '?') . ' to ' . ($model->destination ?: '?'),
                    '/dashboard/packages?view=' . $model->id),
                $model instanceof User && ! UserController::$creatingFromAdmin => StaffAlert::toStaff('customer', 'New customer',
                    $model->name . ' signed up' . ($model->phone ? ' (' . $model->phone . ')' : ''),
                    '/dashboard/users?view=' . $model->id),
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('Staff alert failed: ' . $e->getMessage());
        }

        // A customer who signed up in the app gets the welcome email (accounts an admin makes get
        // theirs from Admin\UserController, which knows the role).
        if ($model instanceof User && ! UserController::$creatingFromAdmin) {
            app(\App\Services\AccountEmails::class)->created($model);
        }
    }
}
