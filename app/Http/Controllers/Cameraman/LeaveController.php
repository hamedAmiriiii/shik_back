<?php

namespace App\Http\Controllers\Cameraman;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(): \Illuminate\Http\Response
    {
        $leaves = Leave::where('user_id', Auth::user()->id)->paginate();
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
            'date_form' => 'required|date|after:tomorrow',
            'date_to' => 'required|date|after:date_from',
        ]);

        $leave = Leave::create([
            'date_form' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
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
            'date_form' => 'required|date|after:tomorrow',
            'date_to' => 'required|date|after:date_from',
        ]);

        $leave->update([
            'date_form' => $request->input('date_from'),
            'date_to' => $request->input('date_to')
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
