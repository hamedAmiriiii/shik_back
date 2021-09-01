<?php

namespace App\Http\Controllers;

use App\Models\ConfirmationCode;
use App\Tools\SmsTools;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ConfirmationCodeController extends Controller
{
    /**
     * Show the form for creating a new resource.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function check(Request $request)
    {
        $request->validate([
            'phone' => "required|numeric|digits:11",
            'code' => "required|numeric"
        ]);

        $code = ConfirmationCode::where('phone', $request->input('phone'))
            ->whereBetween('created_at', [now()->subMinutes(3), now()])
            ->where('code', $request->input('code'))
            ->count();

        if ($code){
            return response([
                'message' => "کد ارسالی تایید شد"
            ]);
        }else{
            return response([
                'message' => "کد ارسالی نا معتبر است"
            ], 400);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'phone' => "required|numeric|digits:11"
        ]);

        $countToday = ConfirmationCode::whereDate('created_at', Carbon::today())->where('phone', $request->input('phone'))->count();
        if ($countToday >= 3) {
            return response([
                'message' => "تعداد درخواست های مجاز به پایان رسیده لطفا ساعاتی دیگر تلاش کنید"
            ], 400);
        }

        $countThreeMinutesAgo = ConfirmationCode::where('phone', $request->input('phone'))->whereBetween('created_at', [now()->subMinutes(3), now()])->count();
        if ($countThreeMinutesAgo > 0) {
            return response([
                'message' => "شما سه دقیقه اخیر درخواستی داشته ایید"
            ], 400);
        }

        $confirmationCode = ConfirmationCode::create([
            'phone' => $request->input("phone"),
            'code' => mt_rand(10000, 99999)
        ]);

        $text = "کد احراز هویت شما : $confirmationCode->code";

        $balance = SmsTools::sendSms($request->input("phone"), $text);

        return response([
            'message' => 'کد ارسال شد',
            'smsResult' => $balance
        ], 201);
    }
}
