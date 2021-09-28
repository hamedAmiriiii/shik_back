<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Atelier;
use App\Models\User;
use App\Tools\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string',
            'last_name' => 'required|string',
            'atelier_id' => 'nullable|numeric',
            'type' => 'required|array|min:1',
            'type.*' => 'required|numeric|digits:1',
            'gender' => 'required|numeric|digits:1',
            'password' => 'required|string|max:255',
            'phone' => 'required|numeric|digits:11',
            'national_code' => 'required|string|digits:10',
            'atelier_name' => 'required_if:type,' . User::USER_TYPE_KEY["آتلیه دار"] . '|string|max:255',
            'atelier_code' => 'required_if:type,' . User::USER_TYPE_KEY["آتلیه دار"] . '|string|max:50',
            'atelier_address' => 'required_if:type,' . User::USER_TYPE_KEY["آتلیه دار"] . '|string|max:255',
            'business_license' => 'required_if:type,' . User::USER_TYPE_KEY["آتلیه دار"] . '|string',
            'personality_image' => 'required|string',
            'birth_certificate' => 'required|string',
            'national_cart' => 'required|string',
        ]);

        $user = User::where('phone', $fields
        ['phone'])->where('national_code', $fields['national_code'])->first();
        if (!$user) {
            $user = User::create([
                'name' => $fields['name'],
                'last_name' => $fields['last_name'],
                'atelier_id' => $fields['atelier_id'],
                'gender' => $fields['gender'],
                'phone' => $fields['phone'],
                'national_code' => $fields['national_code'],
                'password' => bcrypt($fields['password']),
                'personality_image' =>
                    ImageTools::saveFile($fields['national_code'] . "/personality_image.jpeg", base64_decode(explode(",", $request->input("personality_image"))[1])),
                'birth_certificate' =>
                    ImageTools::saveFile($fields['national_code'] . "/birth_certificate.jpeg", base64_decode(explode(",", $request->input("birth_certificate"))[1])),
                'national_cart' =>
                    ImageTools::saveFile($fields['national_code'] . "/national_code.jpeg", base64_decode(explode(",", $request->input("national_cart"))[1]))
            ]);

        }
        $roles = $user->roles()->select("id")->pluck("id")->toArray();
        $diffs = array_diff( $fields['type'],$roles);
        if (sizeof($diffs)) {
            $user->roles()->attach($diffs);
            $user->save();
        }
        if (in_array(User::USER_TYPE_KEY["آتلیه دار"], $request->input("type"))){
        //if ($request->input("type") == User::USER_TYPE_KEY["آتلیه دار"]) {
            $atelier = Atelier::create([
                "name" => $request->input('atelier_name'),
                "code" => $request->input('atelier_code'),
                "address" => $request->input('atelier_address'),
                "business_license" =>
                    ImageTools::saveFile($fields['national_code'] . "/business_license.jpeg", base64_decode(explode(",", $request->input("business_license"))[1]))
            ]);
            $user->update([
                'atelier_id' => $atelier->id
            ]);
        }

        $user->load("roles");
        $token = $user->createToken('myapptoken')->plainTextToken;

        $response = [
            'user' => $user,
            'token' => $token
        ];

        return response($response, 201);
    }

    public function login(Request $request)
    {
        $fields = $request->validate([
            'username' => 'required|string|digits:11',
            'password' => 'required|string'
        ]);

        // Check email
        $user = User::where('phone', $fields['username'])->first();

        // Check password
        if (!$user || !Hash::check($fields['password'], $user->password)) {
            return response([
                'message' => 'اطلاعات وارد شده صحیح نیست'
            ], 401);
        }

        $token = $user->createToken('myapptoken')->plainTextToken;

        $user->load('roles');
        $response = [
            'user' => $user,
            'token' => $token
        ];

        return response($response, 201);
    }

    public function logout(Request $request)
    {
        auth()->user()->tokens()->delete();

        return [
            'message' => 'Logged out'
        ];
    }
}
