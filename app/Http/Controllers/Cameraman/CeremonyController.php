<?php

namespace App\Http\Controllers\Cameraman;

use App\Http\Controllers\Controller;
use App\Models\Ceremony;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CeremonyController extends Controller
{
    /**
     * Get list of ceremonies assigned to the authenticated cameraman
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Ceremony::whereHas('cameraman', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with(['talar', 'garden', 'atelier']);
        
        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس نام داماد
                    if (isset($searchDataModel->groom_full_name)) {
                        $q->where('groom_full_name', 'like', '%' . $searchDataModel->groom_full_name . '%');
                    }
                    // جستجو بر اساس شماره تلفن داماد
                    if (isset($searchDataModel->groom_phone)) {
                        $q->orWhere('groom_phone', 'like', '%' . $searchDataModel->groom_phone . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در نام و شماره تلفن داماد جستجو می‌کند
                    $q->where('groom_full_name', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('groom_phone', 'like', '%' . $searchDataModel . '%');
                }
            });
        }
        
        $ceremonies = $query->orderBy('date', 'desc')->paginate();
        return response()->json($ceremonies);
    }
}
