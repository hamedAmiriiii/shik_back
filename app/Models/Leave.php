<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use HasFactory;

    protected $fillable = ['date_from',  'date_to' , 'status' , 'user_id'];


    public function user(){
        return $this->belongsTo(User::class);
    }
}
