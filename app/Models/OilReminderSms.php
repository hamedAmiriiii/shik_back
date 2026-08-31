<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OilReminderSms extends Model
{
    protected $table = 'oil_reminder_sms';

    protected $fillable = [
        'atelier_id',
        'oil_visit_id',
        'plate',
        'plate_display',
        'phone',
        'next_km',
        'estimated_due_on',
        'days_until_due',
        'message',
        'sms_sent',
        'sms_error',
    ];

    protected $casts = [
        'next_km' => 'integer',
        'days_until_due' => 'integer',
        'sms_sent' => 'boolean',
        'estimated_due_on' => 'date',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(OilVisit::class, 'oil_visit_id');
    }

    public function toApiArray(): array
    {
        $due = $this->estimated_due_on;
        $created = $this->created_at;

        return [
            'id' => (int) $this->id,
            'oil_visit_id' => (int) $this->oil_visit_id,
            'plate' => $this->plate,
            'plate_display' => $this->plate_display,
            'phone' => $this->phone,
            'next_km' => (int) $this->next_km,
            'estimated_due_on' => $due ? $due->format('Y-m-d') : null,
            'estimated_due_on_jalali' => $due ? jdate($due)->format('Y/m/d') : null,
            'days_until_due' => $this->days_until_due,
            'message' => $this->message,
            'sms_sent' => (bool) $this->sms_sent,
            'sms_error' => $this->sms_error,
            'created_at' => $created ? $created->format('Y-m-d H:i:s') : null,
            'created_at_jalali' => $created ? jdate($created)->format('Y/m/d H:i') : null,
        ];
    }
}
