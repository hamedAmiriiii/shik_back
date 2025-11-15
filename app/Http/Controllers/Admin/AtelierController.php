<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\AuthController;
use App\Models\StatusEnum;
use App\Tools\SmsTools;
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
        $user = auth()->user();
        
        $query = User::whereHas("roles", function($q) {
            $q->where('id', User::USER_TYPE_KEY["آتلیه دار"]);
        })->with(['atelier', 'roles']);

        // فیلتر شهر برای همه کاربران (حتی ادمین‌ها) اعمال می‌شود
        if ($user->city_id) {
            $query->whereHas('atelier', function($q) use ($user) {
                $q->where('city_id', $user->city_id);
            });
        }

        // جستجو
        if ($search = $request->input('searchFilterModel')) {
            $searchData = json_decode($search, true);
            $query->where(function($q) use ($searchData) {
                if (is_array($searchData)) {
                    if (isset($searchData['name'])) {
                        $q->where('name', 'like', '%' . $searchData['name'] . '%');
                    }
                    if (isset($searchData['phone'])) {
                        $q->orWhere('phone', 'like', '%' . $searchData['phone'] . '%');
                    }
                } else {
                    $q->where('name', 'like', '%' . $searchData . '%')
                      ->orWhere('phone', 'like', '%' . $searchData . '%');
                }
            });
        }

        $users = $query->orderBy('id', 'desc')->paginate();
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
        $response = (new AuthController)->register($request);
        
        // If the user was created successfully, update their city_id
        if ($response->getStatusCode() === 201) {
            $userData = json_decode($response->content(), true);
            $user = User::find($userData['user']['id']);
            
            if ($user && auth()->user()->city_id) {
                $user->update(['city_id' => auth()->user()->city_id]);
                
                // Reload the user with updated data
                $user->load('atelier', 'roles');
                
                // Return the updated user data
                return response($user, 201);
            }
        }
        
        return $response;
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
     * @param Request $request
     * @param User $atelier
     * @return \Illuminate\Http\Response
     */
    public function confirm(Request $request , User $atelier){
        $request->validate([
            "status" => "required|numeric|max:3|digits:1"
        ]);
        foreach ($atelier->roles as $role){
            if ($role->id == User::USER_TYPE_KEY["آتلیه دار"]){
                $atelier->roles()->syncWithoutDetaching([$role->id => ["status" =>  $request->input("status")]]);
                $text = "درخواست شما برای ثبت واحد صنفی " . StatusEnum::STATUS[$request->input("status")] . " است.";
                SmsTools::sendSms($atelier->phone, $text);
            }
        }
        $atelier->load("roles");
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
        $atelier->roles()->detach();
        $atelier->delete();
        return response([
            'message' => "حذف با موفقیت انجام شد"
        ]);
    }
}
