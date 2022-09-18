<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ceremony;
use App\Models\StatusEnum;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Morilog\Jalali\Jalalian;

class CeremonyController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        $ceremonies = Ceremony::search($searchDataModel)
            ->with(['talar', 'garden', 'atelier'])
            ->orderBy('id', 'desc')
            ->paginate();
        return response($ceremonies);
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
            "talar_id" => "required|numeric|exists:talars,id",
            "garden_id" => "required|numeric|exists:gardens,id",
            "groom_full_name" => "required|string",
            "groom_phone" => "required|digits:11|numeric",
            "groom_national_code" => "required|digits:10|numeric",
            "manCameraman" => "required|array|min:1",
            "manCameraman.*" => "required|numeric|exists:users,id",
            "womanCameraman" => "required|array|min:1",
            "womanCameraman.*" => "required|numeric|exists:users,id",
            "manPhotographer" => "required|array|min:1",
            "manPhotographer.*" => "required|numeric|exists:users,id",
            "womanPhotographer" => "required|array|min:1",
            "womanPhotographer.*" => "required|numeric|exists:users,id",
            "manAirCameraman" => "required|array|min:1",
            "manAirCameraman.*" => "required|numeric|exists:users,id",
            "womanAirCameraman" => "required|array|min:1",
            "womanAirCameraman.*" => "required|numeric|exists:users,id",
            'date.year' => 'required',
            'date.month' => 'required',
            'date.day' => 'required',
        ]);

        $date = $request->input('date');
        $date = new Jalalian($date["year"], $date["month"], $date["day"]);

        $ceremony = Ceremony::create([
            "talar_id" => $request->input('talar_id'),
            "garden_id" => $request->input('garden_id'),
            "atelier_id" => Auth::user()->atelier->id,
            "groom_full_name" => $request->input('groom_full_name'),
            "groom_phone" => $request->input('groom_phone'),
            "groom_national_code" => $request->input('groom_national_code'),
            "date" => $date->toCarbon(),
            "status" => 2
        ]);

        $ceremony->cameraman()->attach(array_merge($request->input('manCameraman'), $request->input('womanCameraman')));

        $ceremony->photographer()->attach(array_merge($request->input('manPhotographer'), $request->input('womanPhotographer')));

        $ceremony->airCameraman()->attach(array_merge($request->input('manAirCameraman'), $request->input('womanAirCameraman')));

        $text = "ثبت مراسم\n" .
            "تاریخ : $ceremony->date \n" .
            "اتلیه : " . $ceremony->atelier->name . "\n" .
            "داماد: $ceremony->groom_full_name \n" .
            "تالار : " . $ceremony->talar->name;

        SmsTools::sendSms($ceremony->groom_phone, $text);
        SmsTools::sendSms($ceremony->atelier->user->phone, $text . "\n آتلیه");
        foreach ($ceremony->cameraman as $cameraman) {
            SmsTools::sendSms($cameraman->phone, $text . "\n فیلم بردار");
        }
        foreach ($ceremony->photographer as $cameraman) {
            SmsTools::sendSms($cameraman->phone, $text . "\n عکاس");
        }
        foreach ($ceremony->airCameraman as $cameraman) {
            SmsTools::sendSms($cameraman->phone, $text . "\n فیلم بردار هوایی");
        }
        if ($ceremony->talar) {
            $text .= "\n" . "فیلم برداران" . "\n";
            foreach ($ceremony->cameraman as $cameraman) {
                $text .= $cameraman->name . $cameraman->last_name . "\n";
            }
            foreach ($ceremony->photographer as $cameraman) {
                $text .= $cameraman->name . $cameraman->last_name . "\n";
            }
            foreach ($ceremony->airCameraman as $cameraman) {
                $text .= $cameraman->name . $cameraman->last_name . "\n";
            }
            SmsTools::sendSms($ceremony->talar->phone, $text);
        }
        if ($ceremony->garden) {
            SmsTools::sendSms($ceremony->garden->phone, $text);
        }

        return response($ceremony, 201);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Ceremony $ceremony
     * @return \Illuminate\Http\Response
     */
    public function show(Ceremony $ceremony)
    {
        $ceremony->load(['womanCameraman', 'manCameraman', 'womanPhotographer', 'manPhotographer', 'womanAirCameraman', 'manAirCameraman']);
        return response($ceremony);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Ceremony $ceremony
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Ceremony $ceremony)
    {
        $request->validate([
            "talar_id" => "required|numeric|exists:talars,id",
            "garden_id" => "required|numeric|exists:gardens,id",
            "groom_full_name" => "required|string",
            "groom_phone" => "required|digits:11|numeric",
            "groom_national_code" => "required|digits:10|numeric",
            "manCameraman" => "required|array|min:1",
            "manCameraman.*" => "required|numeric|exists:users,id",
            "womanCameraman" => "required|array|min:1",
            "womanCameraman.*" => "required|numeric|exists:users,id",
            "manPhotographer" => "required|array|min:1",
            "manPhotographer.*" => "required|numeric|exists:users,id",
            "womanPhotographer" => "required|array|min:1",
            "womanPhotographer.*" => "required|numeric|exists:users,id",
            "manAirCameraman" => "required|array|min:1",
            "manAirCameraman.*" => "required|numeric|exists:users,id",
            "womanAirCameraman" => "required|array|min:1",
            "womanAirCameraman.*" => "required|numeric|exists:users,id",
            'date.year' => 'required',
            'date.month' => 'required',
            'date.day' => 'required',
        ]);

        $date = $request->input('date');
        $date = new Jalalian($date["year"], $date["month"], $date["day"]);

        $ceremony->update([
            "talar_id" => $request->input('talar_id'),
            "garden_id" => $request->input('garden_id'),
            "groom_full_name" => $request->input('groom_full_name'),
            "groom_phone" => $request->input('groom_phone'),
            "groom_national_code" => $request->input('groom_national_code'),
            "status" => StatusEnum::STATUS_KEYS['در انتظار بررسی'],
            "date" => $date->toCarbon()
        ]);

        $ceremony->cameraman()->sync(array_merge($request->input('manCameraman'), $request->input('womanCameraman')));

        $ceremony->photographer()->sync(array_merge($request->input('manPhotographer'), $request->input('womanPhotographer')));

        $ceremony->airCameraman()->attach(array_merge($request->input('manAirCameraman'), $request->input('womanAirCameraman')));

        return response($ceremony);
    }

    /**
     * @param Request $request
     * @param Ceremony $ceremony
     * @return \Illuminate\Http\Response
     */
    public function confirm(Request $request, Ceremony $ceremony): \Illuminate\Http\Response
    {
        $request->validate([
            "status" => "required|numeric|max:3|digits:1"
        ]);
        $ceremony->update([
            "status" => $request->input("status")
        ]);
        if ($request->input("status") == 2) {
            /*$text = "ثبت مراسم\n" .
                "تاریخ : $ceremony->date \n" .
                "اتلیه : " . $ceremony->atelier->name . "\n" .
                "داماد: $ceremony->groom_full_name \n" .
                "تالار : " . $ceremony->talar->name;

            SmsTools::sendSms($ceremony->groom_phone, $text);
            SmsTools::sendSms($ceremony->atelier->user->phone, $text . "\n آتلیه");
            foreach ($ceremony->cameraman as $cameraman) {
                SmsTools::sendSms($cameraman->phone, $text . "\n فیلم بردار");
            }
            foreach ($ceremony->photographer as $cameraman) {
                SmsTools::sendSms($cameraman->phone, $text . "\n عکاس");
            }
            foreach ($ceremony->airCameraman as $cameraman) {
                SmsTools::sendSms($cameraman->phone, $text . "\n فیلم بردار هوایی");
            }
            if ($ceremony->talar) {
                $text .= "\n" . "فیلم برداران" . "\n";
                foreach ($ceremony->cameraman as $cameraman) {
                    $text .= $cameraman->name . $cameraman->last_name . "\n";
                }
                foreach ($ceremony->photographer as $cameraman) {
                    $text .= $cameraman->name . $cameraman->last_name . "\n";
                }
                foreach ($ceremony->airCameraman as $cameraman) {
                    $text .= $cameraman->name . $cameraman->last_name . "\n";
                }
                SmsTools::sendSms($ceremony->talar->phone, $text);
            }
            if ($ceremony->garden) {
                SmsTools::sendSms($ceremony->garden->phone, $text);
            }*/
        } else {
            $text = "عدم تایید مراسم\n" .
                "تاریخ : $ceremony->date \n" .
                "داماد: $ceremony->groom_full_name \n" .
                "تالار : " . $ceremony->talar->name;
            SmsTools::sendSms($ceremony->atelier->user->phone, $text);
        }
        return response($ceremony);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Ceremony $ceremony
     * @return \Illuminate\Http\Response
     */
    public function destroy(Ceremony $ceremony)
    {
        $ceremony->delete();
        return response([
            'message' => "حذف با موفقیت انجام شد"
        ]);
    }
}
