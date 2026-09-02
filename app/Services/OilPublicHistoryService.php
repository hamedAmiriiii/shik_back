<?php

namespace App\Services;

use App\Models\OilVisit;
use App\Tools\PhoneTools;
use App\Tools\PlateTools;
use Illuminate\Support\Facades\Schema;

class OilPublicHistoryService
{
    public static function historyUrl(string $phone): string
    {
        $base = rtrim((string) config('oil.public_base_url', 'https://webinoo-plus.ir'), '/');

        return $base.'/oilservice/'.$phone;
    }

    public static function resolvePhone(string $raw): ?string
    {
        $phone = PhoneTools::normalizeIranPhone(PlateTools::toEnglishDigits($raw));
        if (! PhoneTools::isValidIranMobile($phone)) {
            return null;
        }

        return $phone;
    }

    /**
     * @return array{phone: string, cars: array<int, array<string, mixed>>}|null
     */
    public static function payload(string $rawPhone): ?array
    {
        $phone = self::resolvePhone($rawPhone);
        if (! $phone) {
            return null;
        }

        $cars = [];
        if (Schema::hasTable('oil_visits')) {
            $visits = OilVisit::query()
                ->withItems()
                ->with('atelier')
                ->where('phone', $phone)
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

        return [
            'phone' => $phone,
            'cars' => array_values($cars),
        ];
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
