<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class Leave extends Model
{
    use HasFactory,QueryTools;

    protected $fillable = ['date_from',  'date_to' , 'status' , 'user_id'];

    public function getDateFromAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('date');
    }

    public function getDateToAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('date');
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
}
