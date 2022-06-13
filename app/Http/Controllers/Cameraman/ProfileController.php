<?php

namespace App\Http\Controllers\Cameraman;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\MatchOldPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    /**
     * @param Request $request
     * @param User $user
     * @return User
     */
    public function resetPassword(Request $request , User $user){
        $request->validate([
            'current_password' => ['required', new MatchOldPassword()],
            'new_password' => ['required'],
            'new_confirm_password' => ['same:new_password'],
        ]);

        return User::find(auth()->user()->id)->update(['password'=> Hash::make($request->new_password)]);
    }
}
