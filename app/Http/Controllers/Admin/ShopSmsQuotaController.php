<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Atelier;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\ShopSmsQuotaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopSmsQuotaController extends Controller
{
    /**
     * لیست گرید فروشگاه‌ها: نام، تلفن، تاریخ اعتبار، تعداد کالا، پیامک.
     */
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $query = Atelier::query()
            ->leftJoin('settings as sms_quota_setting', function ($join) {
                $join->on('sms_quota_setting.atelier_id', '=', 'ateliers.id')
                    ->where('sms_quota_setting.key', ShopSmsQuotaService::SETTING_KEY);
            })
            ->select([
                'ateliers.id as atelier_id',
                'ateliers.name as shop_name',
                'ateliers.code as shop_code',
                'ateliers.address as shop_address',
                'ateliers.shop_access_starts_at',
                'ateliers.shop_access_ends_at',
                'ateliers.shop_access_suspended',
                DB::raw('COALESCE(sms_quota_setting.value, 0) as shop_sms_quota'),
            ])
            ->orderByDesc('ateliers.id');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->shop_name) || isset($searchDataModel->name)) {
                        $name = $searchDataModel->shop_name ?? $searchDataModel->name;
                        $q->where('ateliers.name', 'like', '%'.$name.'%');
                    }
                    if (isset($searchDataModel->shop_code) || isset($searchDataModel->code)) {
                        $code = $searchDataModel->shop_code ?? $searchDataModel->code;
                        $q->orWhere('ateliers.code', 'like', '%'.$code.'%');
                    }
                    if (isset($searchDataModel->phone)) {
                        $phone = $searchDataModel->phone;
                        $q->orWhereHas('staffUsers', function ($uq) use ($phone) {
                            $uq->where('phone', 'like', '%'.$phone.'%');
                        });
                    }
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->where('ateliers.name', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('ateliers.code', 'like', '%'.$searchDataModel.'%')
                        ->orWhereHas('staffUsers', function ($uq) use ($searchDataModel) {
                            $uq->where('phone', 'like', '%'.$searchDataModel.'%');
                        });
                }
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $paginator = $query->paginate($perPage);
        $paginator->withPath(url()->current());

        $atelierIds = collect($paginator->items())->pluck('atelier_id')->filter()->map(fn ($id) => (int) $id)->all();
        $ownersByAtelier = $this->loadShopOwnersByAtelierIds($atelierIds);
        $productCounts = $this->loadProductCountsByAtelierIds($atelierIds);

        $paginator->getCollection()->transform(function ($row) use ($ownersByAtelier, $productCounts) {
            return $this->formatShopGridRow($row, $ownersByAtelier, $productCounts);
        });

        $payload = $paginator->toArray();
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
            'setting_key' => ShopSmsQuotaService::SETTING_KEY,
            'trial_months' => Atelier::TRIAL_MONTHS,
        ]);

        return response($payload, 200);
    }

    public function show(Request $request, Atelier $atelier)
    {
        $this->requirePlatformAdmin($request);

        $atelier->refresh();
        $summary = ShopSmsQuotaService::getSummary((int) $atelier->id);
        $owners = $this->loadShopOwnersByAtelierIds([(int) $atelier->id]);
        $owner = $owners[(int) $atelier->id] ?? null;
        $productsCount = (int) Product::where('atelier_id', $atelier->id)->count();

        return response(array_merge($summary, $atelier->accessStatusForApi(), [
            'shop_name' => $atelier->name,
            'shop_code' => $atelier->code,
            'phone' => $owner['phone'] ?? null,
            'owner_phone' => $owner['phone'] ?? null,
            'owner_name' => $owner['name'] ?? null,
            'products_count' => $productsCount,
        ]), 200);
    }

    /**
     * ویرایش تعداد پیامک و/یا تاریخ اعتبار استفاده فروشگاه.
     */
    public function update(Request $request, Atelier $atelier)
    {
        $admin = $this->requirePlatformAdmin($request);

        $fields = $request->validate([
            'shop_sms_quota' => 'sometimes|integer|min:0|max:1000000',
            'balance' => 'sometimes|integer|min:0|max:1000000',
            'shop_access_ends_at' => 'sometimes|nullable|date',
            'shop_access_starts_at' => 'sometimes|nullable|date',
            'shop_access_suspended' => 'sometimes|boolean',
        ]);

        $hasSms = $request->has('shop_sms_quota') || $request->has('balance');
        $hasAccess = $request->has('shop_access_ends_at')
            || $request->has('shop_access_starts_at')
            || $request->has('shop_access_suspended');

        if (! $hasSms && ! $hasAccess) {
            return response()->json([
                'message' => 'حداقل یکی از فیلدهای shop_sms_quota، shop_access_ends_at یا shop_access_suspended را بفرستید.',
            ], 422);
        }

        if ($hasSms) {
            $newBalance = (int) ($fields['shop_sms_quota'] ?? $fields['balance']);
            ShopSmsQuotaService::setBalance((int) $atelier->id, $newBalance);
        }

        if ($hasAccess) {
            $update = [];
            if (array_key_exists('shop_access_starts_at', $fields)) {
                $update['shop_access_starts_at'] = $fields['shop_access_starts_at']
                    ? $this->parseAccessDate($fields['shop_access_starts_at'], false)
                    : null;
            }
            if (array_key_exists('shop_access_ends_at', $fields)) {
                $update['shop_access_ends_at'] = $fields['shop_access_ends_at']
                    ? $this->parseAccessDate($fields['shop_access_ends_at'], true)
                    : null;
            }
            if (array_key_exists('shop_access_suspended', $fields)) {
                $update['shop_access_suspended'] = (bool) $fields['shop_access_suspended'];
            }
            $atelier->update($update);
        }

        $atelier->refresh();

        $owners = $this->loadShopOwnersByAtelierIds([(int) $atelier->id]);
        $productCounts = $this->loadProductCountsByAtelierIds([(int) $atelier->id]);
        $row = (object) [
            'atelier_id' => $atelier->id,
            'shop_name' => $atelier->name,
            'shop_code' => $atelier->code,
            'shop_address' => $atelier->address,
            'shop_access_starts_at' => $atelier->shop_access_starts_at,
            'shop_access_ends_at' => $atelier->shop_access_ends_at,
            'shop_access_suspended' => $atelier->shop_access_suspended,
            'shop_sms_quota' => ShopSmsQuotaService::getBalance((int) $atelier->id),
        ];

        return response([
            'message' => 'اطلاعات فروشگاه با موفقیت به‌روزرسانی شد',
            'updated_by_user_id' => $admin->id,
            'shop' => $this->formatShopGridRow($row, $owners, $productCounts),
        ], 200);
    }

    /**
     * شارژ اعتبار پیامک فروشگاه (افزودن به موجودی فعلی).
     */
    public function charge(Request $request, Atelier $atelier)
    {
        $admin = $this->requirePlatformAdmin($request);

        $fields = $request->validate([
            'amount' => 'required|integer|min:1|max:1000000',
        ]);

        $added = (int) $fields['amount'];
        $newBalance = ShopSmsQuotaService::charge((int) $atelier->id, $added);

        return response([
            'message' => 'اعتبار پیامک با موفقیت شارژ شد',
            'atelier_id' => (int) $atelier->id,
            'shop_name' => $atelier->name,
            'added' => $added,
            'shop_sms_quota' => $newBalance,
            'balance' => $newBalance,
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
            'charged_by_user_id' => $admin->id,
        ], 200);
    }

    /**
     * @param  object  $row
     * @param  array<int, array{phone: string, name: string}>  $ownersByAtelier
     * @param  array<int, int>  $productCounts
     */
    protected function formatShopGridRow(object $row, array $ownersByAtelier, array $productCounts): array
    {
        $aid = (int) $row->atelier_id;
        $owner = $ownersByAtelier[$aid] ?? null;

        $startsAt = $row->shop_access_starts_at
            ? Carbon::parse($row->shop_access_starts_at)
            : null;
        $endsAt = $row->shop_access_ends_at
            ? Carbon::parse($row->shop_access_ends_at)
            : null;
        $suspended = (bool) ($row->shop_access_suspended ?? false);

        $atelier = new Atelier([
            'shop_access_starts_at' => $startsAt,
            'shop_access_ends_at' => $endsAt,
            'shop_access_suspended' => $suspended,
        ]);

        return array_merge([
            'atelier_id' => $aid,
            'shop_name' => $row->shop_name,
            'name' => $row->shop_name,
            'shop_code' => $row->shop_code,
            'shop_address' => $row->shop_address ?? null,
            'phone' => $owner['phone'] ?? null,
            'owner_phone' => $owner['phone'] ?? null,
            'owner_name' => $owner['name'] ?? null,
            'products_count' => (int) ($productCounts[$aid] ?? 0),
            'shop_sms_quota' => (int) $row->shop_sms_quota,
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
        ], $atelier->accessStatusForApi());
    }

    /**
     * @param  int[]  $atelierIds
     * @return array<int, int>
     */
    protected function loadProductCountsByAtelierIds(array $atelierIds): array
    {
        if ($atelierIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('atelier_id', $atelierIds)
            ->groupBy('atelier_id')
            ->selectRaw('atelier_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'atelier_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    protected function parseAccessDate(string $value, bool $endOfDay): Carbon
    {
        $date = Carbon::parse($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    /**
     * @param  int[]  $atelierIds
     * @return array<int, array{phone: string, name: string}>
     */
    protected function loadShopOwnersByAtelierIds(array $atelierIds): array
    {
        if ($atelierIds === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('atelier_id', $atelierIds)
            ->whereHas('roles', function ($q) {
                $q->where('id', User::USER_TYPE_KEY['فروشگاه']);
            })
            ->orderBy('id')
            ->get(['id', 'atelier_id', 'phone', 'name', 'last_name']);

        $map = [];
        foreach ($users as $user) {
            $aid = (int) $user->atelier_id;
            if (! isset($map[$aid])) {
                $map[$aid] = [
                    'phone' => $user->phone,
                    'name' => trim($user->name.' '.$user->last_name),
                ];
            }
        }

        return $map;
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
            abort(response()->json(['message' => 'فقط ادمین می‌تواند فروشگاه‌ها را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
