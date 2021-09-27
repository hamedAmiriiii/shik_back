<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Atelier extends Model
{
    use HasFactory, QueryTools;


    protected $fillable = ["name", "code", "address", "business_license"];

    public function user()
    {
        return $this->hasOne(User::class);
    }

}
