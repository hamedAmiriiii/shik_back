<?php

namespace App\Http\Controllers;

use App\Models\ShopEmployee;
use App\Models\User;
use App\Services\ShopEmployeeAccountService;
use App\Services\ShopPermissionCatalog;
use App\Services\ShopStaffAccess;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ShopEmployeeController extends Controller
{
    public function permissionOptions(Request $request)
    {
        $this->requireStaffShopUser($request);
        $this->shopAtelierIdOrAbort($request);

        return response([
            'permissions' => ShopPermissionCatalog::options(),
        ], 200);
    }

    public function index(Request $request)
    {
        if (! Schema::hasTable('shop_employees')) {
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

        $this->normalizeEmployeeRequest($request);

        $fields = $request->validate($this->rules(false), $this->ruleMessages());

        $data = [
            'atelier_id' => $atelierId,
            'name' => $fields['name'],
            'phone' => $fields['phone'] ?? null,
            'is_active' => array_key_exists('is_active', $fields) ? (bool) $fields['is_active'] : true,
            'base_salary' => $fields['base_salary'] ?? 0,
            'base_work_hours' => $fields['base_work_hours'] ?? 0,
            'hourly_wage' => $fields['hourly_wage'] ?? 0,
            'note' => $fields['note'] ?? null,
        ];
        $permissions = $this->permissionsFromRequest($request, $fields);
        if ($permissions !== null && Schema::hasColumn('shop_employees', 'permissions')) {
            $data['permissions'] = $permissions;
        }

        $employee = ShopEmployee::create($data);

        try {
            $this->syncLoginIfRequested($request, $employee, $atelierId, $fields);
        } catch (RuntimeException $e) {
            $employee->delete();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($employee->fresh(), 201);
    }

    public function show(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);

        return response($shopEmployee, 200);
    }

    public function update(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        $this->normalizeEmployeeRequest($request);

        $fields = $request->validate($this->rules(true), $this->ruleMessages());
        $actor = $this->requireStaffShopUser($request);

        $payload = $fields;
        unset($payload['password'], $payload['permissions'], $payload['username']);

        if (array_key_exists('permissions', $fields)) {
            if (! ShopStaffAccess::isOwner($actor)) {
                return response()->json([
                    'message' => 'فقط صاحب فروشگاه می‌تواند لیست دسترسی کارمند را تغییر دهد.',
                    'error' => 'فقط صاحب فروشگاه می‌تواند لیست دسترسی کارمند را تغییر دهد.',
                    'permission' => 'employees',
                    'permission_label' => ShopPermissionCatalog::labelFor('employees'),
                ], 403);
            }
            if (Schema::hasColumn('shop_employees', 'permissions')) {
                $payload['permissions'] = $this->permissionsFromRequest($request, $fields);
            }
        }

        $shopEmployee->update($payload);

        try {
            $this->syncLoginIfRequested($request, $shopEmployee, (int) $shopEmployee->atelier_id, $fields);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (array_key_exists('is_active', $fields) && ! $shopEmployee->is_active) {
            ShopEmployeeAccountService::revokeLogin($shopEmployee);
        }

        return response($shopEmployee->fresh(), 200);
    }

    public function destroy(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        ShopEmployeeAccountService::deleteLoginUser($shopEmployee);
        $shopEmployee->delete();

        return response(['message' => 'کارمند با موفقیت حذف شد.'], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $updating): array
    {
        $req = $updating ? 'sometimes|' : '';

        return [
            'name' => $req.'required|string|max:255',
            'phone' => ($updating ? 'sometimes|' : '').'nullable|string|regex:/^09\d{9}$/',
            'username' => 'sometimes|nullable|string|regex:/^09\d{9}$/',
            'is_active' => ($updating ? 'sometimes|' : 'nullable|').'boolean',
            'base_salary' => ($updating ? 'sometimes|' : 'nullable|').'numeric|min:0',
            'base_work_hours' => ($updating ? 'sometimes|' : 'nullable|').'numeric|min:0',
            'hourly_wage' => ($updating ? 'sometimes|' : 'nullable|').'numeric|min:0',
            'note' => ($updating ? 'sometimes|' : 'nullable|').'nullable|string|max:2000',
            'password' => 'sometimes|nullable|string|min:6|max:255',
            'permissions' => 'sometimes|nullable|array',
            'permissions.*' => 'string|in:'.implode(',', ShopPermissionCatalog::keys()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ruleMessages(): array
    {
        return [
            'permissions.array' => 'لیست دسترسی باید آرایه باشد.',
            'permissions.*.in' => 'یکی از دسترسی‌های انتخاب‌شده معتبر نیست.',
            'permissions.*.string' => 'کلید دسترسی نامعتبر است.',
            'password.min' => 'رمز ورود کارمند باید حداقل ۶ کاراکتر باشد.',
            'phone.regex' => 'نام کاربری باید شماره موبایل ۱۱ رقمی معتبر باشد.',
            'username.regex' => 'نام کاربری باید شماره موبایل ۱۱ رقمی معتبر باشد.',
        ];
    }

    private function normalizeEmployeeRequest(Request $request): void
    {
        $this->mergeRequestPayload($request, ['name', 'employee_name', 'phone', 'username', 'is_active', 'password', 'permissions']);

        if (! $request->filled('name') && $request->filled('employee_name')) {
            $request->merge(['name' => $request->input('employee_name')]);
        }
        if (! $request->filled('phone') && $request->filled('username')) {
            $request->merge(['phone' => $request->input('username')]);
        }
        if ($request->has('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<int, string>|null
     */
    private function permissionsFromRequest(Request $request, array $fields): ?array
    {
        if (! Schema::hasColumn('shop_employees', 'permissions')) {
            return null;
        }
        if (! $request->exists('permissions') && ! array_key_exists('permissions', $fields)) {
            return null;
        }

        return ShopPermissionCatalog::sanitize($fields['permissions'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function syncLoginIfRequested(Request $request, ShopEmployee $employee, int $atelierId, array $fields): void
    {
        $password = $fields['password'] ?? null;
        $hasPassword = is_string($password) && $password !== '';
        $hasPermissions = $request->exists('permissions') || array_key_exists('permissions', $fields);
        $actor = $this->shopRequestActor($request);
        $owner = $actor instanceof User && ShopStaffAccess::isOwner($actor);

        if (($hasPassword || $hasPermissions) && ! $owner) {
            throw new RuntimeException('فقط صاحب فروشگاه می‌تواند رمز ورود و لیست دسترسی کارمند را تنظیم کند.');
        }

        if (! $hasPassword && ! $employee->user_id) {
            return;
        }
        if (! $hasPassword && ! $owner) {
            return;
        }
        if (! Schema::hasColumn('shop_employees', 'user_id')) {
            throw new RuntimeException('ستون ورود کارمند وجود ندارد. فایل SQL دسترسی کارمندان را اجرا کنید.');
        }

        $employee->refresh();
        ShopEmployeeAccountService::syncLogin(
            $employee,
            $atelierId,
            $hasPassword ? $password : null
        );
    }
}
