<?php

namespace App\Services;

use App\Models\ProducedGood;
use App\Tools\PriceTools;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * منوی سه‌بعدی: ترکیب لایه‌ای عکس کالای تولیدی و قیمت هر ماده.
 *
 * قیمت هر ماده = سهم هزینهٔ آن ماده از کل هزینهٔ فرمول × قیمت فروش کالا (sale_share).
 * فقط موادی که در فرمول «قابل حذف» علامت خورده‌اند قابل برداشتن هستند.
 */
class ThreedMenuService
{
    /**
     * مواد فرمول با سهم قیمت.
     * پیش‌فرض: اگر attachCost قبلاً صدا زده نشده باشد، سهم‌ها صفر می‌شوند.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ingredients(ProducedGood $good): array
    {
        $good->loadMissing('ingredients.rawMaterial');

        $costByMaterial = [];
        foreach ((array) ($good->getAttribute('ingredient_costs') ?? []) as $line) {
            $costByMaterial[(int) $line['raw_material_id']] = (float) ($line['cost'] ?? 0);
        }
        $totalCost = array_sum($costByMaterial);
        $salePrice = (float) $good->sale_price;

        $hasRemovable = Schema::hasColumn('produced_good_ingredients', 'is_removable');
        $count = max(1, $good->ingredients->count());

        $rows = [];
        foreach ($good->ingredients as $line) {
            $material = $line->rawMaterial;
            if (! $material) {
                continue;
            }
            $id = (int) $line->raw_material_id;
            $share = $totalCost > 0
                ? ($costByMaterial[$id] ?? 0) / $totalCost
                : 1 / $count;
            $path = $material->getAttributes()['layer_image_path'] ?? null;

            $rows[] = [
                'raw_material_id' => $id,
                'name' => $material->name,
                'image_url' => $path ? Storage::url($path) : null,
                'is_removable' => $hasRemovable ? (bool) $line->is_removable : false,
                'price' => (float) PriceTools::roundToman($salePrice * $share),
            ];
        }

        return $rows;
    }

    /**
     * لایه‌ها از بالا به پایین؛ در صورت نبودن ترتیب دستی، ترتیب فرمول.
     *
     * @param  array<int, array<string, mixed>>  $ingredients
     * @return array<int, array<string, mixed>>
     */
    public function layers(ProducedGood $good, array $ingredients): array
    {
        $byId = [];
        foreach ($ingredients as $row) {
            $byId[$row['raw_material_id']] = $row;
        }

        $order = Schema::hasColumn('produced_goods', 'layer_order')
            ? (array) ($good->layer_order ?? [])
            : [];
        if ($order === []) {
            $order = array_keys($byId);
        }

        $layers = [];
        foreach ($order as $index => $materialId) {
            $materialId = (int) $materialId;
            if (! isset($byId[$materialId]) || empty($byId[$materialId]['image_url'])) {
                continue;
            }
            $layers[] = [
                'key' => $materialId.'-'.$index,
                'raw_material_id' => $materialId,
                'name' => $byId[$materialId]['name'],
                'image_url' => $byId[$materialId]['image_url'],
            ];
        }

        return $layers;
    }

    /**
     * فیلدهای اضافه برای آیتم کاتالوگ عمومی.
     *
     * @return array<string, mixed>
     */
    public function catalogFields(ProducedGood $good): array
    {
        $ingredients = $this->ingredients($good);

        return [
            'ingredients' => $ingredients,
            'layers' => $this->layers($good, $ingredients),
        ];
    }

    /**
     * مواد حذف‌شده / اضافه‌شده و تغییر قیمت (سمت سرور، بدون اعتماد به قیمت مشتری).
     * هر ماده حداکثر یک واحد اضافه می‌شود؛ مادهٔ حذف‌شده نمی‌تواند همزمان اضافه باشد.
     *
     * @param  array<int, int|string>  $removedIds
     * @param  array<int, int|string>  $extraIds
     * @return array{
     *   removed: array<int, array<string, mixed>>,
     *   extra: array<int, array<string, mixed>>,
     *   discount: float,
     *   surcharge: float
     * }
     */
    public function resolveCustomization(ProducedGood $good, array $removedIds = [], array $extraIds = []): array
    {
        $wantedRemoved = array_values(array_unique(array_map('intval', $removedIds)));
        $wantedExtra = array_values(array_unique(array_map('intval', $extraIds)));
        // حذف و اضافه هم‌زمان برای یک ماده بی‌معناست
        $wantedExtra = array_values(array_diff($wantedExtra, $wantedRemoved));

        if ($wantedRemoved === [] && $wantedExtra === []) {
            return ['removed' => [], 'extra' => [], 'discount' => 0.0, 'surcharge' => 0.0];
        }

        app(ProducedGoodCostService::class)->attachCost($good, 1.0, true);

        $removed = [];
        $extra = [];
        $discount = 0.0;
        $surcharge = 0.0;

        foreach ($this->ingredients($good) as $row) {
            $id = (int) $row['raw_material_id'];
            $payload = [
                'raw_material_id' => $id,
                'name' => $row['name'],
                'price' => $row['price'],
            ];
            if ($row['is_removable'] && in_array($id, $wantedRemoved, true)) {
                $removed[] = $payload;
                $discount += (float) $row['price'];
            } elseif (in_array($id, $wantedExtra, true)) {
                $extra[] = $payload;
                $surcharge += (float) $row['price'];
            }
        }

        $discount = min($discount, max(0, (float) $good->sale_price));

        return [
            'removed' => $removed,
            'extra' => $extra,
            'discount' => $discount,
            'surcharge' => $surcharge,
        ];
    }

    /**
     * @deprecated از resolveCustomization استفاده کنید.
     *
     * @param  array<int, int|string>  $removedIds
     * @return array{removed: array<int, array<string, mixed>>, discount: float}
     */
    public function resolveRemoval(ProducedGood $good, array $removedIds): array
    {
        $result = $this->resolveCustomization($good, $removedIds, []);

        return ['removed' => $result['removed'], 'discount' => $result['discount']];
    }
}
