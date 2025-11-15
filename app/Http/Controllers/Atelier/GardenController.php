<?php

namespace App\Http\Controllers\Atelier;

use App\Http\Controllers\Controller;
use App\Models\Garden;
use App\Models\StatusEnum;
use Illuminate\Http\Request;

class GardenController extends Controller
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
        
        $query = Garden::where("status", StatusEnum::STATUS_KEYS["تایید شده"]);
        
        if ($user->city_id) {
            $query->where('city_id', $user->city_id);
        }
        
        $gardens = $query->search($searchDataModel)
            ->orderBy('id', 'desc')
            ->get();
            
        return response($gardens);
    }
}
