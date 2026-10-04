<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class RepairRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_PAYMENT_REVIEW = 'payment_review';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'در انتظار ارجاع',
        self::STATUS_ASSIGNED => 'ارجاع به تعمیرکار',
        self::STATUS_IN_PROGRESS => 'در حال انجام',
        self::STATUS_INVOICED => 'در انتظار پرداخت',
        self::STATUS_PAYMENT_REVIEW => 'بررسی رسید پرداخت',
        self::STATUS_COMPLETED => 'انجام شد',
        self::STATUS_CANCELED => 'لغو شد',
    ];

    public const METHOD_ONLINE = 'online';

    public const METHOD_CARD_TO_CARD = 'card_to_card';

    public const METHOD_CASH = 'cash';

    public const METHOD_LABELS = [
        self::METHOD_ONLINE => 'پرداخت آنلاین',
        self::METHOD_CARD_TO_CARD => 'کارت به کارت',
        self::METHOD_CASH => 'نقدی / ثبت ادمین',
    ];

    protected $table = 'repair_requests';

    protected $fillable = [
        'customer_id',
        'technician_id',
        'service_id',
        'category',
        'description',
        'address',
        'latitude',
        'longitude',
        'contact_name',
        'contact_phone',
        'preferred_time',
        'status',
        'admin_note',
        'labor_amount',
        'parts_amount',
        'total_amount',
        'cost_description',
        'share_percent',
        'technician_share',
        'platform_share',
        'payment_method',
        'payment_ref',
        'gateway_payment_id',
        'receipt_path',
        'receipt_submitted_at',
        'receipt_reject_reason',
        'assigned_at',
        'started_at',
        'invoiced_at',
        'paid_at',
        'completed_at',
        'canceled_at',
        'cancel_reason',
        'rating',
        'review',
        'rated_at',
    ];

    protected $casts = [
        'labor_amount' => 'integer',
        'parts_amount' => 'integer',
        'total_amount' => 'integer',
        'share_percent' => 'float',
        'technician_share' => 'integer',
        'platform_share' => 'integer',
        'receipt_submitted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'invoiced_at' => 'datetime',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'rating' => 'integer',
        'rated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(RepairUser::class, 'customer_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(RepairUser::class, 'technician_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELED], true);
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_INVOICED && (int) $this->total_amount > 0;
    }

    public function canBeRated(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->technician_id && $this->rating === null;
    }

    public function receiptUrl(): ?string
    {
        if (! $this->receipt_path) {
            return null;
        }

        return url(Storage::url($this->receipt_path));
    }

    /**
     * @param  string  $audience  admin | technician | customer
     * @return array<string, mixed>
     */
    public function toApiArray(string $audience): array
    {
        $technician = $this->technician;
        $customer = $this->customer;

        $row = [
            'id' => (int) $this->id,
            'service_id' => $this->service_id ? (int) $this->service_id : null,
            'category' => $this->category,
            'description' => $this->description,
            'address' => $this->address,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'preferred_time' => $this->preferred_time,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'labor_amount' => (int) $this->labor_amount,
            'parts_amount' => (int) $this->parts_amount,
            'total_amount' => (int) $this->total_amount,
            'cost_description' => $this->cost_description,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method ? (self::METHOD_LABELS[$this->payment_method] ?? $this->payment_method) : null,
            'payment_ref' => $this->payment_ref,
            'has_receipt' => (bool) $this->receipt_path,
            'receipt_url' => $this->receiptUrl(),
            'receipt_submitted_at' => $this->receipt_submitted_at,
            'receipt_reject_reason' => $this->receipt_reject_reason,
            'technician' => $technician ? [
                'id' => (int) $technician->id,
                'name' => $technician->name,
                'phone' => $technician->phone,
                'specialty' => $technician->specialty,
                'rating_avg' => $technician->rating_avg !== null ? (float) $technician->rating_avg : null,
                'rating_count' => (int) $technician->rating_count,
            ] : null,
            'rating' => $this->rating !== null ? (int) $this->rating : null,
            'review' => $this->review,
            'rated_at' => $this->rated_at,
            'can_rate' => $this->canBeRated(),
            'assigned_at' => $this->assigned_at,
            'started_at' => $this->started_at,
            'invoiced_at' => $this->invoiced_at,
            'paid_at' => $this->paid_at,
            'completed_at' => $this->completed_at,
            'canceled_at' => $this->canceled_at,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($audience !== 'customer') {
            $row['customer'] = $customer ? [
                'id' => (int) $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
            ] : null;
            $row['share_percent'] = (float) $this->share_percent;
            $row['technician_share'] = (int) $this->technician_share;
        }

        if ($audience === 'admin') {
            $row['platform_share'] = (int) $this->platform_share;
            $row['admin_note'] = $this->admin_note;
            $row['gateway_payment_id'] = $this->gateway_payment_id ? (int) $this->gateway_payment_id : null;
        }

        return $row;
    }
}
