<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AtelierController extends Controller
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
            $query->where('id', User::USER_TYPE_KEY["آتلیه دار"]);
        })->with(['atelier'])->simplePaginate();
        return response($users);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request['type'] = 2;
        return (new AuthController)->register($request);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\User $atelier
     * @return \Illuminate\Http\Response
     */
    public function show(User $atelier)
    {
        $atelier["atelier"] = $atelier->atelier()->first();
        return response($atelier);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\User $atelier
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, User $atelier)
    {
        $request["type"] = 2;
        $fields = $request->validate([
            'name' => 'required|string',
            'last_name' => 'required|string',
            'atelier_id' => 'nullable|numeric',
            'type' => 'required|numeric|digits:1',
            'gender' => 'required|numeric|digits:1',
            'phone' => 'required|numeric|unique:users,phone,' . $atelier->id . '|digits:11',
            'national_code' => 'required|string|unique:users,national_code,' . $atelier->id . '|digits:10',
            'atelier_name' => 'required_if:type,' . User::USER_TYPE_KEY["آتلیه دار"] . '|string|max:255',
            'atelier_code' => 'required_if:type,' . User::USER_TYPE_KEY["آتلیه دار"] . '|string|max:50',
            'atelier_address' => 'required_if:type,' . User::USER_TYPE_KEY["آتلیه دار"] . '|string|max:255',
        ]);

        $atelier->atelier()->update([
            "name" => $request->input('atelier_name'),
            "code" => $request->input('atelier_code'),
            "address" => $request->input('atelier_address'),
        ]);

        $atelier->update([
            'name' => $fields['name'],
            'last_name' => $fields['last_name'],
            'type' => $fields['type'],
            'gender' => $fields['gender'],
            'phone' => $fields['phone'],
            'national_code' => $fields['national_code'],
        ]);
        return response($atelier);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\User $atelier
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $atelier)
    {
        $atelier->atelier()->delete();
        $atelier->delete();
        return response([
            'message' => "حذف با موفقیت انجام شد"
        ]);
    }
}
