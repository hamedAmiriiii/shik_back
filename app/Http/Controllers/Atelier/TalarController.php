<?php

namespace App\Http\Controllers\Atelier;

use App\Http\Controllers\Controller;
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
        $talars = Talar::search($searchDataModel)->get();
        return response($talars);
    }
}
