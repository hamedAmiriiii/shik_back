<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Garden;
use App\Models\Talar;
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
        $gardens = Garden::search($searchDataModel)->simplePaginate();
        return response($gardens);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "phone" => "required|numeric|digits:11"
        ]);
        $garden = Garden::create($fields);
        return response($garden, 201);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Garden  $garden
     * @return \Illuminate\Http\Response
     */
    public function show(Garden $garden)
    {
        return response($garden);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Garden  $garden
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Garden $garden)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "phone" => "required|numeric|digits:11"
        ]);
        $garden->update($fields);
        return response($garden);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Garden  $garden
     * @return \Illuminate\Http\Response
     */
    public function destroy(Garden $garden)
    {
        $garden->delete();
        return response(true);
    }
}
