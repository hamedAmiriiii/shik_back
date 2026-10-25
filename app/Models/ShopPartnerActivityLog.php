<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class ShopPartnerActivityLog extends Model
{
    public const ACTION_PARTNER_CREATE = 'partner_create';

    public const ACTION_PARTNER_UPDATE = 'partner_update';

    public const ACTION_PARTNER_DELETE = 'partner_delete';

    public const ACTION_SETTLEMENT_PAY_ALL = 'settlement_pay_all';

    public const ACTION_SETTLEMENT_PAY_ONE = 'settlement_pay_one';

    public const ACTION_SETTLEMENT_DELETE = 'settlement_delete';

    public const ACTION_LABELS = [
        self::ACTION_PARTNER_CREATE => 'ثبت شریک',
        self::ACTION_PARTNER_UPDATE => 'تغییر شریک / سهم',
        self::ACTION_PARTNER_DELETE => 'حذف شریک',
        self::ACTION_SETTLEMENT_PAY_ALL => 'پرداخت سود همه',
        self::ACTION_SETTLEMENT_PAY_ONE => 'پرداخت سود شریک',
        self::ACTION_SETTLEMENT_DELETE => 'حذف تسویه سود',
    ];

    protected $fillable = [
        'atelier_id',
        'user_id',
        'user_name',
        'action',
        'partner_id',
        'settlement_id',
        'message',
        'payload',
    ];

    protected $casts = [
        'atelier_id' => 'integer',
        'user_id' => 'integer',
        'partner_id' => 'integer',
        'settlement_id' => 'integer',
        'payload' => 'array',
    ];

    public static function tableReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = Schema::hasTable('shop_partner_activity_logs');
        }

        return $ready;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(ShopPartner::class, 'partner_id');
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }

    /**
     * @param  array{
     *   atelier_id: int,
     *   action: string,
     *   user_id?: int|null,
     *   user_name?: string|null,
     *   partner_id?: int|null,
     *   settlement_id?: int|null,
     *   message?: string|null,
     *   payload?: array<string, mixed>|null
     * }  $data
     */
    public static function record(array $data): ?self
    {
        if (! self::tableReady()) {
            return null;
        }

        $action = (string) ($data['action'] ?? '');
        if ($action === '') {
            return null;
        }

        $message = isset($data['message']) ? trim((string) $data['message']) : null;
        if ($message === '') {
            $message = null;
        }

        $userName = isset($data['user_name']) ? trim((string) $data['user_name']) : null;
        if ($userName === '') {
            $userName = null;
        }

        return self::create([
            'atelier_id' => (int) $data['atelier_id'],
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'user_name' => $userName,
            'action' => $action,
            'partner_id' => isset($data['partner_id']) ? (int) $data['partner_id'] : null,
            'settlement_id' => isset($data['settlement_id']) ? (int) $data['settlement_id'] : null,
            'message' => $message,
            'payload' => $data['payload'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $created = $this->created_at;
        $userName = $this->user_name;
        if (! $userName && $this->relationLoaded('user') && $this->user) {
            $userName = trim(($this->user->name ?? '').' '.($this->user->last_name ?? '')) ?: ($this->user->name ?? null);
        }

        return [
            'id' => (int) $this->id,
            'action' => $this->action,
            'action_label' => self::ACTION_LABELS[$this->action] ?? $this->action,
            'partner_id' => $this->partner_id,
            'settlement_id' => $this->settlement_id,
            'user_id' => $this->user_id,
            'user_name' => $userName,
            'message' => $this->message,
            'payload' => $this->payload,
            'created_at' => $created
                ? Jalalian::fromCarbon(Carbon::parse($created)->timezone('Asia/Tehran'))->format('Y-m-d H:i')
                : null,
        ];
    }
}
