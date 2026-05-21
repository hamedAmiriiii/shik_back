<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Atelier;
use App\Models\Customer;
use App\Models\User;
use App\Services\ShopSmsQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopSmsQuotaController extends Controller
{
    /**
     * لیست گرید همه فروشگاه‌ها با موجودی پیامک.
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

        $atelierIds = collect($paginator->items())->pluck('atelier_id')->filter()->all();
        $ownersByAtelier = $this->loadShopOwnersByAtelierIds($atelierIds);

        $paginator->getCollection()->transform(function ($row) use ($ownersByAtelier) {
            $aid = (int) $row->atelier_id;
            $owner = $ownersByAtelier[$aid] ?? null;

            return [
                'atelier_id' => $aid,
                'shop_name' => $row->shop_name,
                'shop_code' => $row->shop_code,
                'shop_address' => $row->shop_address,
                'shop_sms_quota' => (int) $row->shop_sms_quota,
                'owner_phone' => $owner['phone'] ?? null,
                'owner_name' => $owner['name'] ?? null,
                'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
            ];
        });

        $payload = $paginator->toArray();
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
            'setting_key' => ShopSmsQuotaService::SETTING_KEY,
        ]);

        return response($payload, 200);
    }

    public function show(Request $request, Atelier $atelier)
    {
        $this->requirePlatformAdmin($request);

        $summary = ShopSmsQuotaService::getSummary((int) $atelier->id);
        $owners = $this->loadShopOwnersByAtelierIds([(int) $atelier->id]);
        $owner = $owners[(int) $atelier->id] ?? null;

        return response(array_merge($summary, [
            'shop_name' => $atelier->name,
            'shop_code' => $atelier->code,
            'owner_phone' => $owner['phone'] ?? null,
            'owner_name' => $owner['name'] ?? null,
        ]), 200);
    }

    /**
     * ویرایش مستقیم تعداد پیامک قابل ارسال فروشگاه.
     */
    public function update(Request $request, Atelier $atelier)
    {
        $admin = $this->requirePlatformAdmin($request);

        $fields = $request->validate([
            'shop_sms_quota' => 'required_without:balance|integer|min:0|max:1000000',
            'balance' => 'required_without:shop_sms_quota|integer|min:0|max:1000000',
        ]);

        $newBalance = (int) ($fields['shop_sms_quota'] ?? $fields['balance']);
        ShopSmsQuotaService::setBalance((int) $atelier->id, $newBalance);

        return response([
            'message' => 'تعداد پیامک فروشگاه با موفقیت به‌روزرسانی شد',
            'atelier_id' => (int) $atelier->id,
            'shop_name' => $atelier->name,
            'shop_code' => $atelier->code,
            'shop_sms_quota' => $newBalance,
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
            'updated_by_user_id' => $admin->id,
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
            abort(response()->json(['message' => 'فقط ادمین می‌تواند اعتبار پیامک را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
