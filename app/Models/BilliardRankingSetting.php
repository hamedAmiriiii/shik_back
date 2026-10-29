<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BilliardRankingSetting extends Model
{
    protected $table = 'billiard_ranking_settings';

    protected $fillable = ['key', 'value'];

    public static function value(string $key, $default = null)
    {
        $all = self::allValues();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * @return array<string, string|null>
     */
    public static function allValues(): array
    {
        return Cache::remember('billiard_ranking_settings', 60, function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function put(string $key, $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        Cache::forget('billiard_ranking_settings');
    }

    public static function lastTournamentsCount(): int
    {
        $fromDb = (int) self::value('last_tournaments', 0);

        return $fromDb > 0 ? $fromDb : (int) config('billiard.ranking_last_tournaments', 5);
    }
}
