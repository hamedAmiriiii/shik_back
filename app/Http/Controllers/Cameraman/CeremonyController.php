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
        $ceremonies = Ceremony::whereHas('cameraman', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->with(['talar', 'garden', 'atelier'])
            ->orderBy('date', 'desc')
            ->paginate();

        return response()->json($ceremonies);
    }
}
