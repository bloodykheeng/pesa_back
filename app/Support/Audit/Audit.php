<?php

namespace App\Support\Audit;

use App\Contracts\HasSnapshotSchema;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The audit trail's writer: who did what to which record, from where (activity_log table).
 *
 * Model events (App\Observers\AuditObserver) and sign-ins call in here during a request; the entries
 * are written once, when the request ends. So one save that touches a record three times is one
 * entry, a new order's snapshot includes the items added after it, and the role given to a new
 * account shows on its create entry. Outside a request (a queue job, an artisan command) each
 * entry is written at once.
 *
 * Writing an entry never breaks the request: a failure is logged and the save stands.
 *
 * Entry, in activity_log: log_name = module, event, subject, causer, batch_uuid = the request's id,
 * properties = {title, causer {name, email, role}, source, snapshot | changes, request, extra}.
 */
final class Audit
{
    /** A sign-in or failed attempt, a password reset link... events that are not a model save. */
    public const EVENT_LABELS = [
        'created' => 'Created',
        'updated' => 'Edited',
        'deleted' => 'Deleted',
        'signed_in' => 'Signed in',
        'signed_out' => 'Signed out',
        'sign_in_failed' => 'Sign-in failed',
        'password_reset_requested' => 'Reset asked',
        'password_reset' => 'Password reset',
    ];

    /** The events listed under "Sign-ins and passwords" in the admin. */
    public const SIGN_IN_EVENTS = ['signed_in', 'signed_out', 'sign_in_failed', 'password_reset_requested', 'password_reset'];

    /** @var array<string, array<string, mixed>> */
    private array $pending = [];

    private ?string $requestId = null;

    private bool $hooked = false;

    public static function recorder(): self
    {
        return app(self::class);
    }

    public static function created(Model $model): void
    {
        self::recorder()->queue(self::key($model), ['event' => 'created', 'model' => $model]);
    }

    public static function updated(Model $model): void
    {
        $self = self::recorder();
        $key = self::key($model);
        $entry = $self->pending[$key] ?? null;

        // Created in this same request: the create entry is snapshotted at the end, with this in it.
        if ($entry && $entry['event'] === 'created') {
            return;
        }

        $old = $entry['old'] ?? [];
        foreach (array_keys($model->getChanges()) as $field) {
            if (! array_key_exists($field, $old)) {
                $old[$field] = $model->getRawOriginal($field);
            }
        }
        $self->queue($key, ['event' => 'updated', 'model' => $model, 'old' => $old, 'extra_changes' => $entry['extra_changes'] ?? []]);
    }

    /** Called from `deleting`, while children (an order's items) still exist. */
    public static function deleting(Model $model): void
    {
        $self = self::recorder();
        $key = self::key($model);
        $self->queue($key, [
            'event' => 'deleted',
            'model' => $model,
            'snapshot' => Snapshot::of($model),
            'title' => self::titleOf($model),
        ]);
    }

    /** A change that is not a column of the record (e.g. a user's role), added to its edit entry. */
    public static function change(Model $model, string $field, string $label, mixed $old, mixed $new): void
    {
        $self = self::recorder();
        $key = self::key($model);
        $entry = $self->pending[$key] ?? ['event' => 'updated', 'model' => $model, 'old' => []];
        if ($entry['event'] === 'created') {
            return; // the create snapshot reads the role as it ends up
        }
        $entry['extra_changes'][$field] = Snapshot::change($field, $label, $old, $new);
        $self->queue($key, $entry);
    }

    /**
     * An event that is not a model save: signed_in, signed_out, sign_in_failed...
     *
     * @param  array<string, mixed>  $extra
     */
    public static function event(string $event, ?Model $subject, string $description, array $extra = [], ?User $causer = null, string $module = 'Sign-ins'): void
    {
        self::recorder()->queue('event:'.Str::uuid(), [
            'event' => $event,
            'model' => $subject,
            'description' => $description,
            'extra' => $extra,
            'causer' => $causer,
            'module' => $module,
        ]);
    }

    /** Write everything this request recorded. Runs when the request ends. */
    public static function flush(): void
    {
        $self = self::recorder();
        $pending = $self->pending;
        $self->pending = [];
        foreach ($pending as $entry) {
            try {
                $self->write($entry);
            } catch (Throwable $e) {
                Log::warning('Audit trail entry not written: '.$e->getMessage(), ['event' => $entry['event'] ?? null]);
            }
        }
        $self->requestId = null;
    }

    private function queue(string $key, array $entry): void
    {
        $entry['at'] ??= $this->pending[$key]['at'] ?? CarbonImmutable::now();
        $entry['causer'] ??= $this->currentUser();
        $entry['source'] ??= $this->source($entry['causer']);
        $entry['request'] ??= $this->requestContext();

        // Deleted in the request that created it: one delete entry is enough.
        unset($this->pending[$key]);
        $this->pending[$key] = $entry;

        if (! $this->defers()) {
            self::flush();

            return;
        }
        if (! $this->hooked) {
            $this->hooked = true;
            app()->terminating(fn () => self::flush());
        }
    }

    private function write(array $entry): void
    {
        /** @var Model|null $model */
        $model = $entry['model'] ?? null;
        $event = $entry['event'];
        $properties = ['source' => $entry['source'], 'request' => $entry['request']];

        if ($model instanceof Model && in_array($event, ['created', 'updated', 'deleted'], true)) {
            if ($event === 'created') {
                $fresh = $model->newQueryWithoutScopes()->whereKey($model->getKey())->first();
                if (! $fresh) {
                    return; // rolled back, never existed
                }
                $properties['snapshot'] = Snapshot::of($fresh);
                $title = self::titleOf($fresh);
            } elseif ($event === 'updated') {
                $changes = [...Snapshot::changes($model, $entry['old'] ?? []), ...array_values($entry['extra_changes'] ?? [])];
                if (! $changes) {
                    return; // only skipped columns changed (a sign-in time, a push token)
                }
                $properties['changes'] = $changes;
                $title = self::titleOf($model);
            } else {
                $properties['snapshot'] = $entry['snapshot'];
                $title = $entry['title'];
            }
            $module = $model instanceof HasSnapshotSchema ? $model::auditModule() : Str::headline(class_basename($model));
            $description = self::EVENT_LABELS[$event].' '.$title;
        } else {
            $title = $model ? self::titleOf($model) : null;
            $module = $entry['module'] ?? 'Other';
            $description = $entry['description'];
        }

        $properties['title'] = $title;
        if (! empty($entry['extra'])) {
            $properties['extra'] = $entry['extra'];
        }

        $causer = $entry['causer'];
        if ($causer instanceof User) {
            $properties['causer'] = [
                'name' => $causer->name,
                'email' => $causer->email,
                'role' => $causer->getRoleNames()->first(),
            ];
        }

        $logger = activity($module)
            ->event($event)
            ->withProperties($properties)
            ->createdAt($entry['at'])
            ->tap(fn ($activity) => $activity->batch_uuid = $entry['request']['id'] ?? null);
        if ($model instanceof Model) {
            $logger->performedOn($model);
        }
        if ($causer instanceof User) {
            $logger->causedBy($causer);
        }
        $logger->log($description);
    }

    private static function key(Model $model): string
    {
        return $model::class.'#'.$model->getKey();
    }

    private static function titleOf(Model $model): string
    {
        try {
            return $model instanceof HasSnapshotSchema ? $model->auditTitle() : class_basename($model).' #'.$model->getKey();
        } catch (Throwable) {
            return class_basename($model).' #'.$model->getKey();
        }
    }

    private function currentUser(): ?User
    {
        try {
            $user = auth()->user();

            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** admin (the new dashboard), old-admin (the React one, until the cut-over), app, or system. */
    private function source(?User $causer): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'system';
        }
        $path = request()->path();
        if (str_starts_with($path, 'api/admin')) {
            return 'admin';
        }
        if ($causer && $causer->hasAnyRole(User::STAFF_ROLES)) {
            return 'old-admin';
        }

        return 'app';
    }

    private function requestContext(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['id' => $this->requestId ??= (string) Str::uuid(), 'command' => implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3))];
        }
        $request = request();

        return [
            'id' => $this->requestId ??= (string) Str::uuid(),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            // Frozen with the entry: "Chrome 158 on Windows", "The app on Android 14".
            'device' => Device::describe($request->userAgent()),
        ];
    }

    private function defers(): bool
    {
        return app()->runningUnitTests() || ! app()->runningInConsole();
    }
}
