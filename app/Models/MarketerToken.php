<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketerToken extends Model
{
    protected $fillable = ['marketer_id', 'token_hash', 'last_used_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    /** توکن خام فقط یک‌بار برگردانده می‌شود؛ در دیتابیس فقط هش آن ذخیره است. */
    public static function issue(Marketer $marketer): string
    {
        $plain = Str::random(64);
        static::create([
            'marketer_id' => $marketer->id,
            'token_hash' => hash('sha256', $plain),
            'last_used_at' => now(),
        ]);

        return $plain;
    }

    public static function findByPlain(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        return static::query()->where('token_hash', hash('sha256', $plain))->first();
    }
}
