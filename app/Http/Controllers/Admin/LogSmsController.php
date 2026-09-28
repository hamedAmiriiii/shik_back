<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LogSms;
use App\Models\User;
use Illuminate\Http\Request;

class LogSmsController extends Controller
{
    /**
     * لیست پیامک‌های سیستمی (کدهای دوعاملی و ...) — فقط ادمین سامانه.
     */
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $query = LogSms::query()->with('creator:id,name');

        $searchDataModel = json_decode((string) $request->input('searchFilterModel'));
        $terms = [];
        if (is_object($searchDataModel)) {
            foreach (['receivers', 'text', 'number', 'creator_name', 'search'] as $key) {
                if (isset($searchDataModel->{$key}) && trim((string) $searchDataModel->{$key}) !== '') {
                    $terms[$key] = trim((string) $searchDataModel->{$key});
                }
            }
        } elseif (is_string($searchDataModel) && trim($searchDataModel) !== '') {
            $terms['search'] = trim($searchDataModel);
        }
        if ($request->filled('search')) {
            $terms['search'] = trim((string) $request->input('search'));
        }

        if (! empty($terms)) {
            $query->where(function ($q) use ($terms) {
                foreach ($terms as $key => $term) {
                    $like = '%'.$term.'%';
                    if ($key === 'creator_name') {
                        $q->orWhereHas('creator', fn ($uq) => $uq->where('name', 'like', $like));
                    } elseif ($key === 'search') {
                        $q->orWhere('receivers', 'like', $like)
                            ->orWhere('text', 'like', $like)
                            ->orWhere('number', 'like', $like);
                    } else {
                        $q->orWhere($key, 'like', $like);
                    }
                }
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        return response($query->orderByDesc('id')->paginate($perPage));
    }

    protected function requirePlatformAdmin(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof Customer) {
            abort(response()->json(['message' => 'این عملیات فقط برای ادمین است.'], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }
        if (! $actor->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists()) {
            abort(response()->json(['message' => 'فقط ادمین سامانه می‌تواند کدهای دوعاملی را ببیند.'], 403));
        }

        return $actor;
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
