<?php

namespace App\Http\Controllers\Atelier;

use App\Http\Controllers\Controller;
use App\Models\StatusEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class CameramanController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $date = json_decode($request->input('date'));
        $date = new Jalalian($date->year, $date->month, $date->day);


        switch ($request->input('type')) {
            case "3":
            {
                $userIds = DB::table("ceremony_cameraman")->select("user_id")
                    ->join("ceremonies", "ceremony_id", "=", "ceremony_cameraman.id")
                    ->whereDate("ceremonies.date", $date->toCarbon())
                    ->pluck("user_id")->toArray();
                break;
            }
            case "4":
            {
                $userIds = DB::table("ceremony_photographer")->select("user_id")
                    ->join("ceremonies", "ceremony_id", "=", "id")
                    ->whereDate("ceremonies.date", $date->toCarbon())
                    ->pluck("user_id")->toArray();
                break;
            }
            case "5":
            {
                $userIds = DB::table("ceremony_air_cameraman")->select("user_id")
                    ->join("ceremonies", "ceremony_id", "=", "id")
                    ->whereDate("ceremonies.date", $date->toCarbon())
                    ->pluck("user_id")->toArray();
                break;
            }
            default:
                $userIds = [];
        }
        $users = User::select("id", "name", "last_name")
            ->whereHas("roles", function (Builder $query) use ($request) {
                $query->where('id', $request->input('type'))->where("status", StatusEnum::STATUS_KEYS['تایید شده']);
            })
            ->where("gender", User::USER_GENDER[$request->input('gender')])
            ->where(function ($query) {
                return $query
                    ->whereNull('atelier_id')
                    ->orWhere('atelier_id', Auth::user()->atelier_id);
            })
            ->whereNotIn("id", $userIds)
            ->get();

        return response($users);
    }
}
