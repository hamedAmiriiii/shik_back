<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class Talar extends Model
{
    use HasFactory, QueryTools;

    protected $fillable = ["name", "phone"];

    public function scopeRelatedSearch($query, $searchTerms)
    {
        if ($searchTerms->input("type") != null) {
            $date = json_decode($searchTerms->input('date'));
            $date = new Jalalian($date->year, $date->month, $date->day);
            switch ($searchTerms->input("type")) {
                case "0":
                    $query->whereDoesntHave('ceremonies', function ($q) use ($date) {
                        $q->whereDate('date', $date->toCarbon());
                    });
                    break;
                case 1:
                    $query->whereHas('ceremonies', function ( $q) use ($date) {
                        $q->whereDate('date', $date->toCarbon());
                    });
                    break;
            }
        }
    }

    public function ceremonies()
    {
        return $this->hasMany(Ceremony::class);
    }
}
