<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    use HasFactory, QueryTools;

    protected $fillable = ["name", "code"];


    public function cities(){
        return $this->hasMany(City::class);
    }
}
