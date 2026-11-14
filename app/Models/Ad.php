<?php

namespace App\Models;

use App\Casts\Moment;
use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;
use App\Support\DateBounds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ad extends Model implements HasSnapshotSchema
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ads';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'details',
        'photo_url',
        'status',
        'start_date',
        'end_date',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        // Moments in UTC; a day picked without a time is that Kampala day's first moment (docs/timezones.md).
        'start_date' => Moment::class,
        'end_date' => Moment::class,
        'price' => 'decimal:2',
    ];

    /**
     * Ads still running: no end, or an end on today's Kampala day or later. An ad "to 31 Dec" runs all of the 31st,
     * whether its end was saved as the day (its first moment, 00:00 in Kampala) or with a time (the old admin).
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('end_date')
            ->orWhere('end_date', '>=', DateBounds::startInstant(DateBounds::today())));
    }

    public function isRunning(): bool
    {
        return $this->end_date === null || $this->end_date->gte(DateBounds::startInstant(DateBounds::today()));
    }

    protected $attributes = [
        'status' => 'active',
    ];


    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active ads.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return [
            'title' => ['label' => 'Title', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'enum'],
            'start_date' => ['label' => 'Starts', 'type' => 'date'],
            'end_date' => ['label' => 'Ends', 'type' => 'date'],
            'details' => ['label' => 'Details', 'type' => 'text'],
            'photo_url' => ['label' => 'Picture', 'type' => 'file'],
        ];
    }

    public static function auditModule(): string
    {
        return 'Adverts';
    }

    public function auditTitle(): string
    {
        return (string) ($this->title ?: 'Advert #' . $this->id);
    }
}
