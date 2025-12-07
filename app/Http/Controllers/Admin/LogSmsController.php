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
    public function index(Request $request)
    {
        $user = auth()->user();
        
        $query = LogSms::query();

        // فیلتر شهر برای همه کاربران (حتی ادمین‌ها) اعمال می‌شود
        if ($user->city_id) {
            $query->whereHas('creator', function($q) use ($user) {
                $q->where('city_id', $user->city_id);
            });
        }

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس شماره تلفن
                    if (isset($searchDataModel->number)) {
                        $q->where('number', 'like', '%' . $searchDataModel->number . '%');
                    }
                    // جستجو بر اساس متن پیام
                    if (isset($searchDataModel->text)) {
                        $q->orWhere('text', 'like', '%' . $searchDataModel->text . '%');
                    }
                    // جستجو بر اساس گیرندگان
                    if (isset($searchDataModel->receivers)) {
                        $q->orWhere('receivers', 'like', '%' . $searchDataModel->receivers . '%');
                    }
                    // جستجو بر اساس نام کاربر
                    if (isset($searchDataModel->creator_name)) {
                        $q->orWhereHas('creator', function($userQuery) use ($searchDataModel) {
                            $userQuery->where('name', 'like', '%' . $searchDataModel->creator_name . '%');
                        });
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در شماره تلفن، متن پیام، گیرندگان و نام کاربر جستجو می‌کند
                    $q->where('number', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('text', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('receivers', 'like', '%' . $searchDataModel . '%')
                      ->orWhereHas('creator', function($userQuery) use ($searchDataModel) {
                          $userQuery->where('name', 'like', '%' . $searchDataModel . '%');
                      });
                }
            });
        }

        $logSms = $query->with('creator')
                       ->orderBy('id', 'desc')
                       ->paginate();
                       
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
