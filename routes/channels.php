<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Reverb channels (docs/realtime.md). Authorised at POST /api/admin/broadcasting/auth with the admin's Sanctum token.
 * They carry live "refresh" signals only (App\Events\StaffSignal), never data.
 */

// One staff member's own signals (their bell).
Broadcast::channel('App.Models.User.{id}', fn (User $user, $id) => (int) $user->id === (int) $id);

// Signals for every staff member (new orders, queues, message progress). Customers' app tokens are refused.
Broadcast::channel('staff', fn (User $user) => $user->hasAnyRole(User::STAFF_ROLES));
