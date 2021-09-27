<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Atelier;
use App\Models\StatusEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AtelierController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $atelier = Atelier::whereHas("user.roles", function (Builder $query) {
            $query->where('role_id', User::USER_TYPE_KEY["آتلیه دار"])->where("status" , StatusEnum::STATUS_KEYS['تایید شده']);
        })->get();
        return response($atelier);
    }
}
