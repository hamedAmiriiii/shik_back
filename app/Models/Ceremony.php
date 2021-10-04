<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ceremony extends Model
{
    use HasFactory, QueryTools;

    protected $fillable = ['talar_id', 'garden_id', 'atelier_id', 'groom_full_name', 'groom_phone', 'groom_national_code',
        'date', 'status'];


    public function cameraman(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_cameraman");
    }

    public function womanCameraman(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_cameraman")->where("gender", User::USER_GENDER_KEY["زن"]);
    }

    public function manCameraman(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_cameraman")->where("gender", User::USER_GENDER_KEY["مرد"]);
    }

    public function photographer(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_photographer");
    }

    public function womanPhotographer(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_photographer")->where("gender", User::USER_GENDER_KEY["زن"]);
    }

    public function manPhotographer(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_photographer")->where("gender", User::USER_GENDER_KEY["مرد"]);
    }

    public function airCameraman(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_air_cameraman");
    }

    public function womanAirCameraman(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_air_cameraman")->where("gender", User::USER_GENDER_KEY["زن"]);
    }

    public function manAirCameraman(): BelongsToMany
    {
        return $this->belongsToMany(User::class, "ceremony_air_cameraman")->where("gender", User::USER_GENDER_KEY["مرد"]);
    }

    public function talar(){
        return $this->belongsTo(Talar::class);
    }

    public function garden(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Garden::class);
    }
}
