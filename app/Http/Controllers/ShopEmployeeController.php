<?php

namespace App\Http\Controllers;

use App\Models\ShopEmployee;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;

class ShopEmployeeController extends Controller
{
    public function index(Request $request)
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('shop_employees')) {
            return response()->json([
                'message' => 'جدول shop_employees وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = ShopEmployee::query()
            ->where('atelier_id', $atelierId)
            ->orderByDesc('id');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->name)) {
                        $q->where(function ($inner) use ($searchDataModel) {
                            $inner->where('name', 'like', '%'.$searchDataModel->name.'%');
                            if (isset($searchDataModel->phone)) {
                                $inner->orWhere('phone', 'like', '%'.$searchDataModel->phone.'%');
                            }
                        });
                    } elseif (isset($searchDataModel->phone)) {
                        $q->where('phone', 'like', '%'.$searchDataModel->phone.'%');
                    }
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->where('name', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('phone', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        return response($query->paginate($perPage), 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت کارمند فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $this->mergeRequestPayload($request, ['name', 'employee_name', 'phone', 'is_active']);

        if (! $request->filled('name') && $request->filled('employee_name')) {
            $request->merge(['name' => $request->input('employee_name')]);
        }

        if ($request->has('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|regex:/^09\d{9}$/',
            'is_active' => 'nullable|boolean',
            'base_salary' => 'nullable|numeric|min:0',
            'base_work_hours' => 'nullable|numeric|min:0',
            'hourly_wage' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:2000',
        ]);

        $employee = ShopEmployee::create([
            'atelier_id' => $atelierId,
            'name' => $fields['name'],
            'phone' => $fields['phone'] ?? null,
            'is_active' => array_key_exists('is_active', $fields) ? (bool) $fields['is_active'] : true,
            'base_salary' => $fields['base_salary'] ?? 0,
            'base_work_hours' => $fields['base_work_hours'] ?? 0,
            'hourly_wage' => $fields['hourly_wage'] ?? 0,
            'note' => $fields['note'] ?? null,
        ]);

        return response($employee, 201);
    }

    public function show(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);

        return response($shopEmployee, 200);
    }

    public function update(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);

        $this->mergeRequestPayload($request, ['name', 'employee_name', 'phone', 'is_active']);

        if (! $request->filled('name') && $request->filled('employee_name')) {
            $request->merge(['name' => $request->input('employee_name')]);
        }

        if ($request->has('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|nullable|string|regex:/^09\d{9}$/',
            'is_active' => 'sometimes|boolean',
            'base_salary' => 'sometimes|numeric|min:0',
            'base_work_hours' => 'sometimes|numeric|min:0',
            'hourly_wage' => 'sometimes|numeric|min:0',
            'note' => 'sometimes|nullable|string|max:2000',
        ]);

        $shopEmployee->update($fields);

        return response($shopEmployee, 200);
    }

    public function destroy(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        $shopEmployee->delete();

        return response(['message' => 'کارمند با موفقیت حذف شد.'], 200);
    }
}
