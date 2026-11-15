<?php

namespace App\Services;

use App\Models\LowStockReport;
use App\Models\Product;
use App\Models\RawMaterial;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LowStockReportService
{
    /** اگر حداقل این نسبت از خرید مصرف شده باشد → رو به اتمام */
    public const USED_RATIO_THRESHOLD = 0.5;

    public const TIMEZONE = 'Asia/Tehran';

    public function todayDate(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    public function findForAtelier(int $atelierId): ?LowStockReport
    {
        if (! Schema::hasTable('low_stock_reports')) {
            return null;
        }

        return LowStockReport::query()->where('atelier_id', $atelierId)->first();
    }

    public function canCalculate(?LowStockReport $report): bool
    {
        if (! $report || ! $report->calculated_date) {
            return true;
        }

        return $report->calculated_date->toDateString() !== $this->todayDate();
    }

    /**
     * @return array{report: LowStockReport, created: bool}
     */
    public function calculateAndStore(int $atelierId): array
    {
        if (! Schema::hasTable('low_stock_reports')) {
            throw new \RuntimeException('جدول low_stock_reports وجود ندارد. migration یا SQL را اجرا کنید.');
        }

        $existing = $this->findForAtelier($atelierId);
        if ($existing && ! $this->canCalculate($existing)) {
            throw new \RuntimeException('محاسبه امروز انجام شده است. فردا دوباره می‌توانید بروزرسانی کنید.');
        }

        $payload = $this->buildPayload($atelierId);
        $now = Carbon::now(self::TIMEZONE);

        $report = LowStockReport::query()->updateOrCreate(
            ['atelier_id' => $atelierId],
            [
                'calculated_date' => $now->toDateString(),
                'calculated_at' => $now,
                'payload' => $payload,
            ]
        );

        return [
            'report' => $report->fresh(),
            'created' => ! $existing,
        ];
    }

    /**
     * @return array{
     *   threshold_used_ratio: float,
     *   items: list<array<string, mixed>>,
     *   counts: array{products: int, raw_materials: int, total: int}
     * }
     */
    public function buildPayload(int $atelierId): array
    {
        $products = $this->lowStockProducts($atelierId);
        $materials = $this->lowStockRawMaterials($atelierId);

        $items = array_merge($materials, $products);
        usort($items, function (array $a, array $b) {
            $ra = (float) ($a['used_ratio'] ?? 0);
            $rb = (float) ($b['used_ratio'] ?? 0);
            if ($ra === $rb) {
                return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }

            return $rb <=> $ra;
        });

        return [
            'threshold_used_ratio' => self::USED_RATIO_THRESHOLD,
            'items' => $items,
            'counts' => [
                'products' => count($products),
                'raw_materials' => count($materials),
                'total' => count($items),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lowStockProducts(int $atelierId): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('purchased_products')) {
            return [];
        }

        $soldQuery = DB::table('purchased_products')
            ->join('purchases', 'purchased_products.purchase_id', '=', 'purchases.id')
            ->join('products', 'purchased_products.product_id', '=', 'products.id')
            ->where('products.atelier_id', $atelierId)
            ->whereNotNull('purchased_products.product_id')
            ->where(function ($q) use ($atelierId) {
                $q->where('purchases.atelier_id', $atelierId)
                    ->orWhereNull('purchases.atelier_id');
            })
            ->select(
                'purchased_products.product_id',
                DB::raw('SUM(purchased_products.quantity) as sold_qty')
            )
            ->groupBy('purchased_products.product_id');

        if (Schema::hasColumn('purchased_products', 'produced_good_id')) {
            $soldQuery->whereNull('purchased_products.produced_good_id');
        }
        if (Schema::hasColumn('purchased_products', 'raw_material_id')) {
            $soldQuery->whereNull('purchased_products.raw_material_id');
        }

        $soldMap = $soldQuery->pluck('sold_qty', 'product_id');

        $returnedMap = collect();
        if (
            Schema::hasTable('returned_products')
            && Schema::hasColumn('returned_products', 'product_id')
            && Schema::hasColumn('returned_products', 'quantity')
        ) {
            $returnedQuery = DB::table('returned_products')
                ->join('products', 'returned_products.product_id', '=', 'products.id')
                ->where('products.atelier_id', $atelierId)
                ->whereNotNull('returned_products.product_id')
                ->select('returned_products.product_id', DB::raw('SUM(returned_products.quantity) as returned_qty'))
                ->groupBy('returned_products.product_id');
            $returnedMap = $returnedQuery->pluck('returned_qty', 'product_id');
        }

        $products = Product::query()
            ->where('atelier_id', $atelierId)
            ->get(['id', 'name', 'quantity', 'unit_type']);

        $out = [];
        foreach ($products as $product) {
            $remaining = round((float) ($product->quantity ?? 0), 3);
            $sold = round((float) ($soldMap[$product->id] ?? 0), 3);
            $returned = round((float) ($returnedMap[$product->id] ?? 0), 3);
            $used = max(0, round($sold - $returned, 3));
            $purchased = round($remaining + $used, 3);
            if ($purchased <= 0) {
                continue;
            }

            $row = $this->makeItem(
                'product',
                (int) $product->id,
                (string) $product->name,
                $this->productUnitType($product->unit_type ?? null),
                $purchased,
                $remaining,
                $used
            );
            if ($row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lowStockRawMaterials(int $atelierId): array
    {
        if (! Schema::hasTable('raw_materials') || ! Schema::hasTable('raw_material_lots')) {
            return [];
        }

        $lots = DB::table('raw_material_lots')
            ->where('atelier_id', $atelierId)
            ->select(
                'raw_material_id',
                DB::raw('SUM(quantity_kg) as purchased'),
                DB::raw('SUM(remaining_kg) as remaining')
            )
            ->groupBy('raw_material_id')
            ->get();

        if ($lots->isEmpty()) {
            return [];
        }

        $materials = RawMaterial::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('id', $lots->pluck('raw_material_id')->all())
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($lots as $lot) {
            $material = $materials->get($lot->raw_material_id);
            if (! $material) {
                continue;
            }
            $purchased = round((float) ($lot->purchased ?? 0), 3);
            $remaining = round((float) ($lot->remaining ?? 0), 3);
            $used = max(0, round($purchased - $remaining, 3));
            if ($purchased <= 0) {
                continue;
            }

            $row = $this->makeItem(
                'raw_material',
                (int) $material->id,
                (string) $material->name,
                $material->unitType(),
                $purchased,
                $remaining,
                $used
            );
            if ($row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function makeItem(
        string $kind,
        int $id,
        string $name,
        string $unitType,
        float $purchased,
        float $remaining,
        float $used
    ): ?array {
        if ($purchased <= 0) {
            return null;
        }

        $usedRatio = round($used / $purchased, 4);
        $remainingRatio = round($remaining / $purchased, 4);

        // فقط وقتی بخش قابل‌توجهی مصرف شده باشد (مثلاً ۷۵٪ مصرف → بله؛ ۱۰٪ → خیر)
        if ($usedRatio < self::USED_RATIO_THRESHOLD) {
            return null;
        }

        return [
            'kind' => $kind,
            'id' => $id,
            'name' => $name,
            'unit_type' => $unitType,
            'purchased' => $purchased,
            'remaining' => max(0, $remaining),
            'used' => $used,
            'used_ratio' => $usedRatio,
            'remaining_ratio' => max(0, $remainingRatio),
            'status' => $remaining <= 0 ? 'finished' : 'running_out',
        ];
    }

    private function productUnitType(?string $unitType): string
    {
        if ($unitType === RawMaterial::UNIT_PIECE || $unitType === 'piece') {
            return RawMaterial::UNIT_PIECE;
        }
        if ($unitType === RawMaterial::UNIT_LITER || $unitType === 'liter') {
            return RawMaterial::UNIT_LITER;
        }

        return RawMaterial::UNIT_KG;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(?LowStockReport $report, bool $canCalculate): array
    {
        if (! $report) {
            return [
                'has_report' => false,
                'can_calculate' => true,
                'calculated_at' => null,
                'calculated_date' => null,
                'threshold_used_ratio' => self::USED_RATIO_THRESHOLD,
                'items' => [],
                'counts' => [
                    'products' => 0,
                    'raw_materials' => 0,
                    'total' => 0,
                ],
            ];
        }

        $payload = is_array($report->payload) ? $report->payload : [];

        return [
            'has_report' => true,
            'can_calculate' => $canCalculate,
            'calculated_at' => optional($report->calculated_at)->toIso8601String(),
            'calculated_date' => optional($report->calculated_date)->toDateString(),
            'threshold_used_ratio' => (float) ($payload['threshold_used_ratio'] ?? self::USED_RATIO_THRESHOLD),
            'items' => array_values($payload['items'] ?? []),
            'counts' => [
                'products' => (int) ($payload['counts']['products'] ?? 0),
                'raw_materials' => (int) ($payload['counts']['raw_materials'] ?? 0),
                'total' => (int) ($payload['counts']['total'] ?? 0),
            ],
        ];
    }
}
