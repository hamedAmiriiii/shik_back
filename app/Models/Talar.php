<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Talar extends Model
{
    use HasFactory,QueryTools;

    protected $fillable = ["name" , "phone"];
}
