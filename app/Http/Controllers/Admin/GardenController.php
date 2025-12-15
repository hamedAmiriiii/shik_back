<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Garden;
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
        $query = Garden::query();
        
        // If user is not a super admin, filter by city
        if (!auth()->user()->hasRole('super-admin') && auth()->user()->city_id) {
            $query->where('city_id', auth()->user()->city_id);
        }
        
        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس نام
                    if (isset($searchDataModel->name)) {
                        $q->where('name', 'like', '%' . $searchDataModel->name . '%');
                    }
                    // جستجو بر اساس شماره تلفن
                    if (isset($searchDataModel->phone)) {
                        $q->orWhere('phone', 'like', '%' . $searchDataModel->phone . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در نام و شماره تلفن جستجو می‌کند
                    $q->where('name', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('phone', 'like', '%' . $searchDataModel . '%');
                }
            });
        }
        
        $gardens = $query->relatedSearch($request)->orderBy('id', 'desc')->paginate();
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
        
        // Add city_id from authenticated user
        if (auth()->check() && auth()->user()->city_id) {
            $fields['city_id'] = auth()->user()->city_id;
        }
        
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
        
        // Only allow updating city_id if user is admin
        if (auth()->user()->hasRole('ادمین') && $request->has('city_id')) {
            $fields['city_id'] = $request->input('city_id');
        } elseif (auth()->user()->city_id) {
            // Non-admin users can't change the city_id
            $fields['city_id'] = $garden->city_id;
        }
        
        $garden->update($fields);
        return response($garden);
    }

    /**
     * @param Request $request
     * @param Garden $garden
     * @return \Illuminate\Http\Response
     */
    public function confirm(Request $request,Garden $garden): \Illuminate\Http\Response
    {
        $request->validate([
            "status" => "required|numeric|max:3|digits:1"
        ]);
        $garden->update([
            "status" => $request->input("status")
        ]);
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
        return response([
            'message' => 'حذف با موفقیت انجام شد'
        ]);
    }
}
