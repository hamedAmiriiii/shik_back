<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairPayout extends Model
{
    protected $table = 'repair_payouts';

    protected $fillable = [
        'technician_id',
        'amount',
        'paid_on',
        'method',
        'note',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_on' => 'date:Y-m-d',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(RepairUser::class, 'technician_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'technician_id' => (int) $this->technician_id,
            'technician_name' => $this->technician ? $this->technician->name : null,
            'amount' => (int) $this->amount,
            'paid_on' => $this->paid_on ? $this->paid_on->format('Y-m-d') : null,
            'method' => $this->method,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
