<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\StatusEnum;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Morilog\Jalali\Jalalian;

class LeaveController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request): \Illuminate\Http\Response
    {
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        $leaves = Leave::search($searchDataModel)->with("user")->orderBy('id', 'desc')->paginate();
        return response($leaves);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response
    {
        $request->validate([
            'date_from.year' => 'required',
            'date_from.month' => 'required',
            'date_from.day' => 'required',
            'date_to.year' => 'required',
            'date_to.month' => 'required',
            'date_to.day' => 'required',
        ]);

        $dayFrom = $request->input('date_from');
        $dayTo = $request->input('date_to');

        $dayFrom = new Jalalian($dayFrom["year"], $dayFrom["month"], $dayFrom["day"]);
        $dayTo = new Jalalian($dayTo["year"], $dayTo["month"], $dayTo["day"]);

        $leave = Leave::create([
            'date_from' => $dayFrom->toCarbon(),
            'date_to' => $dayTo->toCarbon(),
            'user_id' => $request->input("user_id"),
        ]);

        return response($leave);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Leave $leave
     * @return \Illuminate\Http\Response
     */
    public function show(Leave $leave): \Illuminate\Http\Response
    {
        $leave->load('user');
        return response($leave);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Leave $leave
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Leave $leave): \Illuminate\Http\Response
    {
        $request->validate([
            'date_from.year' => 'required',
            'date_from.month' => 'required',
            'date_from.day' => 'required',
            'date_to.year' => 'required',
            'date_to.month' => 'required',
            'date_to.day' => 'required',
        ]);

        $dayFrom = $request->input('date_from');
        $dayTo = $request->input('date_to');

        $dayFrom = new Jalalian($dayFrom["year"], $dayFrom["month"], $dayFrom["day"]);
        $dayTo = new Jalalian($dayTo["year"], $dayTo["month"], $dayTo["day"]);

        $leave->update([
            'date_from' => $dayFrom->toCarbon(),
            'date_to' => $dayTo->toCarbon(),
            'status' => StatusEnum::STATUS_KEYS['در انتظار بررسی']
        ]);

        return response($leave);
    }

    /**
     * @param Request $request
     * @param Leave $leave
     * @return \Illuminate\Http\Response
     */
    public function confirm(Request $request,Leave $leave): \Illuminate\Http\Response
    {
        $request->validate([
            "status" => "required|numeric|max:3|digits:1"
        ]);
        $leave->update([
            "status" => $request->input("status")
        ]);
        $text = "درخواست شما برای مرخصی از تاریخ :  ". $leave->date_from
            . " تا تاریخ : " . $leave->date_to
            . StatusEnum::STATUS[$request->input("status")] . " است.";
        SmsTools::sendSms($leave->user->phone, $text);
        return response($leave);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Leave $leave
     * @return \Illuminate\Http\Response
     */
    public function destroy(Leave $leave): \Illuminate\Http\Response
    {
        $leave->delete();
        return response([
            'message' => 'حذف با موفقیت انجام شد'
        ]);
    }
}
