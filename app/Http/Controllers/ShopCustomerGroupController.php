<?php

namespace App\Http\Controllers;

use App\Models\ShopCustomerGroup;
use App\Models\ShopCustomerGroupMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopCustomerGroupController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('shop_customer_groups')) {
            return response(['groups' => []], 200);
        }

        $groups = ShopCustomerGroup::query()
            ->where('atelier_id', $atelierId)
            ->withCount('members')
            ->orderByDesc('id')
            ->get()
            ->map(function (ShopCustomerGroup $group) {
                $phones = $group->members()->pluck('phone')->values()->all();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'member_count' => (int) ($group->members_count ?? count($phones)),
                    'phones' => $phones,
                ];
            })
            ->values();

        return response(['groups' => $groups], 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('shop_customer_groups')) {
            return response(['message' => 'جدول گروه‌ها هنوز ساخته نشده است.'], 503);
        }

        $validated = $request->validate([
            'name' => 'required|string|min:1|max:120',
            'phones' => 'required|array|min:1',
            'phones.*' => 'required|string|digits:11',
        ]);

        $phones = collect($validated['phones'])
            ->map(fn ($p) => trim((string) $p))
            ->filter(fn ($p) => preg_match('/^09\d{9}$/', $p))
            ->unique()
            ->values()
            ->all();

        if (empty($phones)) {
            return response(['message' => 'هیچ شماره معتبری انتخاب نشده است'], 422);
        }

        $group = DB::transaction(function () use ($atelierId, $validated, $phones) {
            $group = ShopCustomerGroup::create([
                'atelier_id' => $atelierId,
                'name' => trim($validated['name']),
            ]);

            foreach ($phones as $phone) {
                ShopCustomerGroupMember::create([
                    'group_id' => $group->id,
                    'phone' => $phone,
                ]);
            }

            return $group;
        });

        $phonesOut = $group->members()->pluck('phone')->values()->all();

        return response([
            'message' => 'گروه ایجاد شد',
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'member_count' => count($phonesOut),
                'phones' => $phonesOut,
            ],
        ], 201);
    }

    public function destroy(Request $request, $group)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('shop_customer_groups')) {
            return response(['message' => 'جدول گروه‌ها هنوز ساخته نشده است.'], 503);
        }

        $model = ShopCustomerGroup::query()
            ->where('atelier_id', $atelierId)
            ->where('id', $group)
            ->first();

        if (! $model) {
            return response(['message' => 'گروه یافت نشد'], 404);
        }

        $model->delete();

        return response(['message' => 'گروه حذف شد'], 200);
    }
}
