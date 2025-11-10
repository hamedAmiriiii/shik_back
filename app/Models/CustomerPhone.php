<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerPhone extends Model
{
    use HasFactory;

    public $table="customer_phones";

    protected $fillable = [
        'phone'
    ];

    public static function createNewPhone($phone){
        $phoneExists = self::where("phone",$phone)->first();
        if ($phoneExists==null){
            self::create([
                'phone'=>$phone
            ]);
            
        }
    }
}
