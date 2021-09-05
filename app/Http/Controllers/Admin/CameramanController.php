<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class CameramanController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $users = User::where("type", User::USER_TYPE_KEY["فیلم بردار"])->with(['atelier'])->paginate();
        return response($users);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
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
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function show(User $cameraman): \Illuminate\Http\Response
    {
        $cameraman->atelier = $cameraman->atelier()->first();
        return response($cameraman);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, User $user)
    {
        $request["type"] = User::USER_TYPE_KEY["فیلم بردار"];
        $fields = $request->validate([
            'name' => 'required|string',
            'last_name' => 'required|string',
            'atelier_id' => 'nullable|numeric',
            'type' => 'required|numeric|digits:1',
            'gender' => 'required|numeric|digits:1',
            'phone' => 'required|numeric|unique:users,phone,' . $user->id . '|digits:11',
            'national_code' => 'required|string|unique:users,national_code,' . $user->id . '|digits:10',
        ]);


        $user->update([
            'name' => $fields['name'],
            'last_name' => $fields['last_name'],
            'gender' => $fields['gender'],
            'phone' => $fields['phone'],
            'national_code' => $fields['national_code'],
        ]);
        return response($user);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        $user->delete();
        return response([
            'message' => "حذف با موفقیت انجام شد"
        ]);
    }
}
