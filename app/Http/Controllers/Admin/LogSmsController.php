<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogSms;
use Illuminate\Http\Request;

class LogSmsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $logSms = LogSms::orderBy('id', 'desc')->paginate();
        return response($logSms);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\LogSms  $logSms
     * @return \Illuminate\Http\Response
     */
    public function show(LogSms $logSms)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\LogSms  $logSms
     * @return \Illuminate\Http\Response
     */
    public function edit(LogSms $logSms)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\LogSms  $logSms
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, LogSms $logSms)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\LogSms  $logSms
     * @return \Illuminate\Http\Response
     */
    public function destroy(LogSms $logSms)
    {
        //
    }
}
