<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    /**
     * دریافت مقدار یک setting
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * تنظیم مقدار یک setting
     * 
     * @param string $key
     * @param mixed $value
     * @return Setting
     */
    public static function set($key, $value)
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * بررسی اینکه آیا یک setting فعال است یا نه (boolean)
     * 
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function isEnabled($key, $default = false)
    {
        $value = self::get($key, $default ? '1' : '0');
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on']);
    }
}

