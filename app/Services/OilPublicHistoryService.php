<?php

namespace App\Services;

use App\Models\Atelier;
use App\Models\OilVisit;
use App\Tools\PhoneTools;
use App\Tools\PlateTools;
use Illuminate\Support\Facades\Schema;

class OilPublicHistoryService
{
    public static function historyUrl(string $phone, ?string $shopCode = null): string
    {
        $base = rtrim((string) config('oil.public_base_url', 'https://webinoo-plus.ir'), '/');
        $code = trim((string) $shopCode);
        if ($code !== '') {
            return $base.'/oilservice/shop/'.rawurlencode($code).'/'.$phone;
        }

        return $base.'/oilservice/'.$phone;
    }

    public static function shopLandingUrl(string $shopCode): string
    {
        $base = rtrim((string) config('oil.public_base_url', 'https://webinoo-plus.ir'), '/');
        $code = trim($shopCode);

        return $base.'/oilservice/shop/'.rawurlencode($code);
    }

    public static function resolvePhone(string $raw): ?string
    {
        $phone = PhoneTools::normalizeIranPhone(PlateTools::toEnglishDigits($raw));
        if (! PhoneTools::isValidIranMobile($phone)) {
            return null;
        }

        return $phone;
    }

    public static function findOilShopByCode(string $rawCode): ?Atelier
    {
        $code = trim($rawCode);
        if ($code === '' || ! Schema::hasTable('ateliers')) {
            return null;
        }

        $shop = Atelier::query()->where('code', $code)->first();
        if (! $shop || ! $shop->isOilProject()) {
            return null;
        }

        return $shop;
    }

    /**
     * @return array{code: string, name: string, landing_url: string}|null
     */
    public static function shopPayload(string $rawCode): ?array
    {
        $shop = self::findOilShopByCode($rawCode);
        if (! $shop) {
            return null;
        }

        $name = trim((string) $shop->name);
        if ($name === '') {
            $name = 'تعویض روغن';
        }

        return [
            'code' => (string) $shop->code,
            'name' => $name,
            'landing_url' => self::shopLandingUrl((string) $shop->code),
        ];
    }

    /**
     * @return array{phone: string, shop?: array{code: string, name: string}, cars: array<int, array<string, mixed>>}|null
     */
    public static function payload(string $rawPhone, ?string $shopCode = null): ?array
    {
        $phone = self::resolvePhone($rawPhone);
        if (! $phone) {
            return null;
        }

        $atelierId = null;
        $shopMeta = null;
        if ($shopCode !== null && trim($shopCode) !== '') {
            $shop = self::findOilShopByCode($shopCode);
            if (! $shop) {
                return null;
            }
            $atelierId = (int) $shop->id;
            $shopMeta = [
                'code' => (string) $shop->code,
                'name' => trim((string) $shop->name) !== '' ? trim((string) $shop->name) : 'تعویض روغن',
            ];
        }

        $cars = [];
        if (Schema::hasTable('oil_visits')) {
            $visits = OilVisit::query()
                ->withItems()
                ->with('atelier')
                ->where('phone', $phone)
                ->when($atelierId !== null, fn ($q) => $q->where('atelier_id', $atelierId))
                ->orderByDesc('id')
                ->get();

            foreach ($visits as $visit) {
                $plate = (string) $visit->plate;
                if (! isset($cars[$plate])) {
                    $cars[$plate] = [
                        'plate_display' => $visit->plate_display,
                        'visits' => [],
                    ];
                }
                $cars[$plate]['visits'][] = self::visitPayload($visit);
            }
        }

        $payload = [
            'phone' => $phone,
            'cars' => array_values($cars),
        ];
        if ($shopMeta) {
            $payload['shop'] = $shopMeta;
        }

        return $payload;
    }

    protected static function visitPayload(OilVisit $visit): array
    {
        $shop = $visit->atelier ? trim((string) $visit->atelier->name) : '';
        $created = $visit->created_at;
        $items = [];
        if ($visit->relationLoaded('items')) {
            foreach ($visit->items as $item) {
                $row = $item->toApiArray();
                $items[] = [
                    'kind_label' => $row['kind_label'] ?? '',
                    'name' => $row['name'] ?? '',
                ];
            }
        }

        return [
            'shop_name' => $shop !== '' ? $shop : 'تعویض روغن',
            'km' => (int) $visit->km,
            'next_km' => (int) $visit->next_km,
            'notes' => $visit->notes !== null && $visit->notes !== '' ? (string) $visit->notes : null,
            'items' => $items,
            'created_at_jalali' => $created ? jdate($created)->format('Y/m/d H:i') : null,
        ];
    }
}
