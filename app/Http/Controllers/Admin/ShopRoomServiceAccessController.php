<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Atelier;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopRoomServiceAccessController extends Controller
{
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $query = Atelier::query()
            ->leftJoin('settings as room_services_setting', function ($join) {
                $join->on('room_services_setting.atelier_id', '=', 'ateliers.id')
                    ->where('room_services_setting.key', 'room_services_enabled');
            })
            ->select([
                'ateliers.id as atelier_id',
                'ateliers.name as shop_name',
                'ateliers.code as shop_code',
                DB::raw("COALESCE(room_services_setting.value, '0') as room_services_enabled"),
            ])
            ->orderByDesc('ateliers.id');

        $search = trim((string) $request->input('search', $request->input('q', '')));
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel && is_object($searchDataModel)) {
            $search = trim((string) ($searchDataModel->shop_name ?? $searchDataModel->shop_code ?? $searchDataModel->phone ?? $search));
        } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
            $search = $searchDataModel;
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('ateliers.name', 'like', '%'.$search.'%')
                    ->orWhere('ateliers.code', 'like', '%'.$search.'%')
                    ->orWhereHas('staffUsers', function ($uq) use ($search) {
                        $uq->where('phone', 'like', '%'.$search.'%');
                    });
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $paginator = $query->paginate($perPage);
        $paginator->withPath(url()->current());

        $atelierIds = collect($paginator->items())->pluck('atelier_id')->filter()->map(fn ($id) => (int) $id)->all();
        $owners = $this->loadShopOwnersByAtelierIds($atelierIds);

        $paginator->getCollection()->transform(function ($row) use ($owners) {
            $id = (int) $row->atelier_id;
            $owner = $owners[$id] ?? null;

            return [
                'id' => $id,
                'atelier_id' => $id,
                'shop_name' => $row->shop_name,
                'shop_code' => $row->shop_code,
                'phone' => $owner['phone'] ?? null,
                'owner_name' => $owner['name'] ?? null,
                'room_services_enabled' => $this->isEnabled($row->room_services_enabled),
            ];
        });

        return response($paginator->toArray(), 200);
    }

    public function update(Request $request, Atelier $atelier)
    {
        $this->requirePlatformAdmin($request);

        $fields = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::setContextAtelierId((int) $atelier->id);
        Setting::set('room_services_enabled', $fields['enabled'] ? '1' : '0');

        return response([
            'message' => $fields['enabled']
                ? 'دسترسی خدمات برای این فروشگاه فعال شد'
                : 'دسترسی خدمات این فروشگاه خاموش شد',
            'atelier_id' => (int) $atelier->id,
            'shop_name' => $atelier->name,
            'shop_code' => $atelier->code,
            'room_services_enabled' => (bool) $fields['enabled'],
        ], 200);
    }

    private function isEnabled($value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param  array<int, int>  $atelierIds
     * @return array<int, array{phone:?string,name:?string}>
     */
    private function loadShopOwnersByAtelierIds(array $atelierIds): array
    {
        if ($atelierIds === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('atelier_id', $atelierIds)
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
            abort(response()->json(['message' => 'فقط ادمین سامانه می‌تواند دسترسی خدمات را بدهد.'], 403));
        }

        return $actor;
    }
}
