<?php

namespace App\Http\Controllers\Cameraman;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\StatusEnum;
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
        $query = Leave::where('user_id', Auth::user()->id);
        
        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس تاریخ از
                    if (isset($searchDataModel->date_from)) {
                        $dateFrom = json_decode($searchDataModel->date_from);
                        if ($dateFrom) {
                            $jalaliFrom = new Jalalian($dateFrom->year, $dateFrom->month, $dateFrom->day);
                            $q->whereDate('date_from', '>=', $jalaliFrom->toCarbon());
                        }
                    }
                    // جستجو بر اساس تاریخ تا
                    if (isset($searchDataModel->date_to)) {
                        $dateTo = json_decode($searchDataModel->date_to);
                        if ($dateTo) {
                            $jalaliTo = new Jalalian($dateTo->year, $dateTo->month, $dateTo->day);
                            $q->whereDate('date_to', '<=', $jalaliTo->toCarbon());
                        }
                    }
                }
            });
        }
        
        $leaves = $query->orderBy('id', 'desc')->paginate();
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
            'user_id' => Auth::user()->id,
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
