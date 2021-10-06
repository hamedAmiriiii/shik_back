<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Morilog\Jalali\Jalalian;

class Talar extends Model
{
    use HasFactory,QueryTools;

    protected $fillable = ["name" , "phone"];

    public function scopeRelatedSearch($query, $searchTerms)
    {
        if ($searchTerms->input("active")) {
            $date = json_decode($searchTerms->input('date'));
            $date = new Jalalian($date->year, $date->month, $date->day);
            switch ($searchTerms->input("active")){
                case 0:
                        return $query->whereDoesntHave('ceremonies',function (Builder $query) use ($date) {
                            $query->whereDate('date', $date);
                        });
                case 1:
                    return $query->whereHas('ceremonies',function (Builder $query) use ($date){
                        $query->whereDate('date', $date);
                    });
            }
        }
        return $query;
    }

    public function ceremonies(){
        return $this->hasMany(Ceremony::class);
    }
}
