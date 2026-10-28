<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChequeBook extends Model
{
    protected $fillable = [
        'atelier_id',
        'title',
        'bank_name',
        'shop_account_id',
        'start_number',
        'end_number',
        'leaf_count',
        'note',
    ];

    protected $casts = [
        'start_number' => 'integer',
        'end_number' => 'integer',
        'leaf_count' => 'integer',
    ];

    protected $appends = [
        'unused_count',
        'used_count',
        'display_title',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(ChequeBookLeaf::class)->orderBy('leaf_number');
    }

    public function unusedLeaves(): HasMany
    {
        return $this->hasMany(ChequeBookLeaf::class)
            ->where('status', ChequeBookLeaf::STATUS_UNUSED)
            ->orderBy('leaf_number');
    }

    public function getUnusedCountAttribute(): int
    {
        if (array_key_exists('unused_leaves_count', $this->attributes)) {
            return (int) $this->attributes['unused_leaves_count'];
        }
        if ($this->relationLoaded('unusedLeaves')) {
            return $this->unusedLeaves->count();
        }
        if ($this->relationLoaded('leaves')) {
            return $this->leaves->where('status', ChequeBookLeaf::STATUS_UNUSED)->count();
        }

        return (int) $this->leaves()->where('status', ChequeBookLeaf::STATUS_UNUSED)->count();
    }

    public function getUsedCountAttribute(): int
    {
        if (array_key_exists('used_leaves_count', $this->attributes)) {
            return (int) $this->attributes['used_leaves_count'];
        }
        if ($this->relationLoaded('leaves')) {
            return $this->leaves->where('status', ChequeBookLeaf::STATUS_USED)->count();
        }

        return (int) $this->leaves()->where('status', ChequeBookLeaf::STATUS_USED)->count();
    }

    public function getDisplayTitleAttribute(): string
    {
        $title = trim((string) ($this->attributes['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        $bank = trim((string) ($this->attributes['bank_name'] ?? ''));
        $range = $this->start_number.' تا '.$this->end_number;

        return $bank !== '' ? $bank.' · '.$range : 'دسته چک '.$range;
    }
}
