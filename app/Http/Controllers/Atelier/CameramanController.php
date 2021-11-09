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

        $users = DB::table("users")->select("users.id", DB::raw("CONCAT(CONCAT(users.name,' ',users.last_name), ' - ' , users.national_code) AS full_name"), "roles.name", "gender")
            ->leftJoin("role_user", "users.id", "=", "role_user.user_id")
            ->leftJoin("roles", "role_user.role_id", "=", "roles.id")
            ->where(function ($query) {
                return $query
                    ->whereNull('atelier_id')
                    ->orWhere('atelier_id', Auth::user()->atelier_id);
            })
            ->where("role_user.status", 2)
            ->whereNotIn('users.id', function ($query) use ($date) {
                $query->select("user_id")->from("ceremony_cameraman")
                    ->join("ceremonies", "ceremony_id", "=", "ceremonies.id")
                    ->where("ceremonies.status", "!=", StatusEnum::STATUS_KEYS['رد شده'])
                    ->whereDate("ceremonies.date", $date->toCarbon());
            })
            ->whereNotIn('users.id', function ($query) use ($date) {
                $query->select("user_id")->from("ceremony_photographer")
                    ->join("ceremonies", "ceremony_id", "=", "ceremonies.id")
                    ->where("ceremonies.status", "!=", StatusEnum::STATUS_KEYS['رد شده'])
                    ->whereDate("ceremonies.date", $date->toCarbon());
            })
            ->whereNotIn('users.id', function ($query) use ($date) {
                $query->select("user_id")->from("ceremony_air_cameraman")
                    ->join("ceremonies", "ceremony_id", "=", "ceremonies.id")
                    ->where("ceremonies.status", "!=", StatusEnum::STATUS_KEYS['رد شده'])
                    ->whereDate("ceremonies.date", $date->toCarbon());
            })
            //->whereNotIn("id", $userIds)
            ->whereNotIn('users.id', function ($query) use ($date) {
                $query->select("user_id")->from("leaves")
                    ->where("leaves.status", StatusEnum::STATUS_KEYS['تایید شده'])
                    ->whereDate("leaves.date_from", '<=', $date->toCarbon())->whereDate("leaves.date_to", '>=', $date->toCarbon());
            })
            ->get();

        return response($users);
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index1(Request $request)
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
