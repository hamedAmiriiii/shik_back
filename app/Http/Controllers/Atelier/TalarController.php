<?php

namespace App\Http\Controllers\Atelier;

use App\Http\Controllers\Controller;
use App\Models\StatusEnum;
use App\Models\Talar;
use Illuminate\Http\Request;

class TalarController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        $user = auth()->user();
        
        $query = Talar::where("status", StatusEnum::STATUS_KEYS["تایید شده"]);
        
        if ($user->city_id) {
            $query->where('city_id', $user->city_id);
        }
        
        $talars = $query->search($searchDataModel)
            ->orderBy('id', 'desc')
            ->get();
            
        return response($talars);
    }
}
