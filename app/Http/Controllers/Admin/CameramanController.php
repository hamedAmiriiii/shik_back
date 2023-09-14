<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Controller;
use App\Models\StatusEnum;
use App\Models\User;
use App\Tools\SmsTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CameramanController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        $users = User::search($searchDataModel)->whereHas("roles", function (Builder $query) {
            $query->whereIn('id', [User::USER_TYPE_KEY["فیلم بردار"] , User::USER_TYPE_KEY["فیلم بردار هوایی"] , User::USER_TYPE_KEY["عکاس"]]);
        })->with(['atelier', 'roles'])->orderBy('id', 'desc')->paginate();
        return response($users);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response
    {
        $request['type'] = User::USER_TYPE_KEY["فیلم بردار"];
        return (new AuthController)->register($request);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Http\Response
     */
    public function show(User $cameraman): \Illuminate\Http\Response
    {
        $cameraman->load('atelier');
        return response($cameraman);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\User $user
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, User $cameraman)
    {
        $fields = $request->validate([
            'name' => 'required|string',
            'last_name' => 'required|string',
            'atelier_id' => 'nullable|numeric',
            'gender' => 'required|numeric|digits:1',
            'phone' => 'required|numeric|unique:users,phone,' . $cameraman->id . '|digits:11',
            'national_code' => 'required|string|unique:users,national_code,' . $cameraman->id . '|digits:10',
        ]);


        $cameraman->update([
            'name' => $fields['name'],
            'last_name' => $fields['last_name'],
            'gender' => $fields['gender'],
            'phone' => $fields['phone'],
            'national_code' => $fields['national_code'],
        ]);
        $cameraman->roles()->sync($request->input('type'));
        $cameraman->load('roles');
        return response($cameraman);
    }

    /**
     * @param Request $request
     * @param User $cameraman
     * @return \Illuminate\Http\Response
     */
    public function confirm(Request $request, User $cameraman): \Illuminate\Http\Response
    {
        $request->validate([
            "status" => "required|numeric|max:3|digits:1",
            "role" => "required|numeric|max:5|digits:1"
        ]);
        foreach ($cameraman->roles as $role) {
            if ($role->id == $request->input("role")) {
                $cameraman->roles()->syncWithoutDetaching([$role->id => ["status" => $request->input("status")]]);
                $text = "درخواست شما با عنوان " . $role->name . " " . StatusEnum::STATUS[$request->input("status")] . " است.";
                SmsTools::sendSms($cameraman->phone, $text);
            }
        }
        $cameraman->load("roles");
        return response($cameraman);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\User $cameraman
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $cameraman)
    {
        $cameraman->atelier()->delete();
        $cameraman->roles()->detach();
        $cameraman->delete();
        return response([
            'message' => "حذف با موفقیت انجام شد"
        ]);
    }
}
