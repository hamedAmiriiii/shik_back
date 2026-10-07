<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;

class CityController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = City::query();

        // فیلتر بر اساس استان (id یا code)
        $stateId = $request->query('state_id');
        if ($stateId !== null && $stateId !== '') {
            $state = \App\Models\State::query()
                ->where('id', $stateId)
                ->orWhere('code', $stateId)
                ->first(['id', 'code']);

            if ($state) {
                $query->where(function ($q) use ($state) {
                    $q->where('state_id', $state->id);
                    if ($state->code !== null && (string) $state->code !== (string) $state->id) {
                        $q->orWhere('state_id', $state->code);
                    }
                });
            } else {
                $query->where('state_id', $stateId);
            }
        }

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->name)) {
                        $q->where('name', 'like', '%'.$searchDataModel->name.'%');
                    }
                } elseif (is_string($searchDataModel)) {
                    $q->where('name', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        $cities = $query->orderBy('name')->get();

        return response($cities);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function show(City $city)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function edit(City $city)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, City $city)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function destroy(City $city)
    {
        //
    }
}
