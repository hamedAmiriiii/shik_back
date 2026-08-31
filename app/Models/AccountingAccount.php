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
        return Schema::hasTable('accounting_accounts');
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
