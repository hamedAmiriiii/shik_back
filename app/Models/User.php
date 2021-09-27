<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, QueryTools;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name', 'last_name', 'national_code', 'phone', 'atelier_id', 'password', 'gender',
        'personality_image', 'birth_certificate', 'national_cart'
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
        3 => "فیلم بردار",
        4 => "عکاس",
        5 => "فیلم بردار هوایی",
    ];

    const USER_TYPE_KEY = [
        "ادمین" => 1,
        "آتلیه دار" => 2,
        "فیلم بردار" => 3,
        "عکاس" => 4,
        "فیلم بردار هوایی" => 5,
    ];

    public function getPersonalityImageAttribute($value): string
    {
        return Storage::url($value);
    }

    public function getBirthCertificateAttribute($value): string
    {
        return Storage::url($value);
    }

    public function getNationalCartAttribute($value): string
    {
        return Storage::url($value);
    }

    public function atelier()
    {
        return $this->belongsTo(Atelier::class);
    }

    /**
     * The roles that belong to the user.
     */
    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('status');
    }
}
