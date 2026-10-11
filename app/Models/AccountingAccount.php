<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class AccountingAccount extends Model
{
    public const LEVEL_GROUP = 'group';

    public const LEVEL_KOL = 'kol';

    public const LEVEL_MOEIN = 'moein';

    public const LEVEL_TAFSILI = 'tafsili';

    public const LEVELS = [
        self::LEVEL_GROUP,
        self::LEVEL_KOL,
        self::LEVEL_MOEIN,
        self::LEVEL_TAFSILI,
    ];

    public const NATURE_DEBIT = 'debit';

    public const NATURE_CREDIT = 'credit';

    public const KIND_ASSET = 'asset';

    public const KIND_LIABILITY = 'liability';

    public const KIND_EQUITY = 'equity';

    public const KIND_REVENUE = 'revenue';

    public const KIND_COGS = 'cogs';

    public const KIND_EXPENSE = 'expense';

    public const LINK_SHOP_ACCOUNT = 'shop_account';

    public const LINK_TILL = 'till';

    protected $fillable = [
        'atelier_id',
        'parent_id',
        'code',
        'name',
        'level',
        'nature',
        'kind',
        'is_system',
        'linked_type',
        'linked_id',
        'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'linked_id' => 'integer',
        'parent_id' => 'integer',
        'atelier_id' => 'integer',
    ];

    public static function tableReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = Schema::hasTable('accounting_accounts');
        }

        return $ready;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * سطح حساب فرزند بر اساس والد: گروه→کل، کل→معین، معین/تفصیلی→تفصیلی (بدون محدودیت عمق).
     */
    public function childLevel(): string
    {
        return match ($this->level) {
            self::LEVEL_GROUP => self::LEVEL_KOL,
            self::LEVEL_KOL => self::LEVEL_MOEIN,
            default => self::LEVEL_TAFSILI,
        };
    }

    /**
     * کد پیشنهادی برای فرزند جدید با توجه به موقعیت والد.
     * قاعده: گروه ۱ رقمی، کل/معین یک رقم بعد از کد والد، تفصیلی و عمیق‌تر دو رقم بعد از کد والد
     * (مثلاً ۱۱۱ → ۱۱۱۰۱). شمارهٔ بعدی از بیشترین کد فرزندان گرفته می‌شود و کد تکراری نمی‌دهد.
     */
    public function suggestChildCode(): string
    {
        $parentCode = (string) $this->code;
        $level = $this->childLevel();
        $width = $level === self::LEVEL_TAFSILI ? 2 : 1;

        $used = static::query()
            ->forAtelier((int) $this->atelier_id)
            ->pluck('code')
            ->flip();

        if (! preg_match('/^\d+$/', $parentCode)) {
            $n = 1;
            while (isset($used[$parentCode.'-'.$n])) {
                $n++;
            }

            return $parentCode.'-'.$n;
        }

        $max = 0;
        $prefixLen = strlen($parentCode);
        foreach ($this->children()->pluck('code') as $childCode) {
            $childCode = (string) $childCode;
            if (strlen($childCode) <= $prefixLen || ! preg_match('/^\d+$/', $childCode)
                || strpos($childCode, $parentCode) !== 0) {
                continue;
            }
            $suffix = (int) substr($childCode, $prefixLen);
            if ($suffix > $max) {
                $max = $suffix;
            }
        }

        $next = $max + 1;
        while (true) {
            $suffixWidth = max($width, strlen((string) $next));
            $code = $parentCode.str_pad((string) $next, $suffixWidth, '0', STR_PAD_LEFT);
            if (! isset($used[$code])) {
                return $code;
            }
            $next++;
        }
    }

    public function levelLabel(): string
    {
        return [
            self::LEVEL_GROUP => 'گروه',
            self::LEVEL_KOL => 'کل',
            self::LEVEL_MOEIN => 'معین',
            self::LEVEL_TAFSILI => 'تفصیلی',
        ][$this->level] ?? $this->level;
    }

    public function natureLabel(): string
    {
        return $this->nature === self::NATURE_CREDIT ? 'بستانکار' : 'بدهکار';
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $withChildren = false): array
    {
        $row = [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'level' => $this->level,
            'level_label' => $this->levelLabel(),
            'nature' => $this->nature,
            'nature_label' => $this->natureLabel(),
            'kind' => $this->kind,
            'is_system' => (bool) $this->is_system,
            'linked_type' => $this->linked_type,
            'linked_id' => $this->linked_id,
            'is_active' => (bool) $this->is_active,
        ];

        if ($withChildren) {
            $row['children'] = $this->children
                ->map(fn (self $child) => $child->toApiArray(true))
                ->values()
                ->all();
        }

        return $row;
    }
}
