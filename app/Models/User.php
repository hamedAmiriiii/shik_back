<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name', 'last_name', 'national_code', 'phone', 'type', 'atelier_id', 'password', 'gender'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    const USER_TYPE = [
        1 => "ادمین",
        2 => "آتلیه دار",
        3 => "فیلم بردار"
    ];

    const USER_TYPE_KEY = [
        "ادمین" => 1,
        "آتلیه دار" => 2,
        "فیلم بردار" => 3
    ];

    public function atelier()
    {
        return $this->belongsTo(Atelier::class);
    }
}
