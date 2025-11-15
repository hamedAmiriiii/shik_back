<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Talar;
use http\Env\Response;
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
        $query = Talar::query();
        
        // If user is not a super admin, filter by city
        if (!auth()->user()->hasRole('super-admin') && auth()->user()->city_id) {
            $query->where('city_id', auth()->user()->city_id);
        }
        
        $talars = $query->relatedSearch($request)->orderBy('id', 'desc')->paginate();
        return response($talars);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "phone" => "required|numeric|digits:11"
        ]);
        
        // Add city_id from authenticated user
        if (auth()->check() && auth()->user()->city_id) {
            $fields['city_id'] = auth()->user()->city_id;
        }
        
        $talar = Talar::create($fields);
        return response($talar, 201);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Talar $talar
     * @return \Illuminate\Http\Response
     */
    public function show(Talar $talar)
    {
        return response($talar);
    }


    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Talar $talar
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Talar $talar)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "phone" => "required|numeric|digits:11"
        ]);
        
        // Only allow updating city_id if user is admin
        if (auth()->user()->hasRole('ادمین') && $request->has('city_id')) {
            $fields['city_id'] = $request->input('city_id');
        } elseif (auth()->user()->city_id) {
            // Non-admin users can't change the city_id
            $fields['city_id'] = $talar->city_id;
        }
        
        $talar->update($fields);
        return response($talar);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Talar $talar
     * @return \Illuminate\Http\Response
     */
    public function destroy(Talar $talar)
    {
        $talar->delete();
        return response([
            'message' => 'حذف با موفقیت انجام شد'
        ]);
    }

    /**
     * @param Request $request
     * @param Talar $talar
     * @return \Illuminate\Http\Response
     */
    public function confirm(Request $request,Talar $talar): \Illuminate\Http\Response
    {
        $request->validate([
            "status" => "required|numeric|max:3|digits:1"
        ]);
        $talar->update([
            "status" => $request->input("status")
        ]);
        return response($talar);
    }
}
