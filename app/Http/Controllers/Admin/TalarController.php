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
        //$searchDataModel = json_decode($request->input('searchFilterModel'));
        $talars = Talar::relatedSearch($request)->orderBy('id', 'desc')->paginate();
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
