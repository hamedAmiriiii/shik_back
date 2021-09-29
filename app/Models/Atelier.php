<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Atelier extends Model
{
    use HasFactory, QueryTools;


    protected $fillable = ["name", "code", "address", "business_license"];

    public function user()
    {
        return $this->hasOne(User::class);
    }


    public function getBusinessLicenseAttribute($value): string
    {
        return Storage::url($value);
    }
}
