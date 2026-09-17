<?php

namespace App\Services\SmartCustomer;

use App\Models\Atelier;
use App\Services\ShopFeatureFlags;
use Illuminate\Support\Facades\Schema;

class SmartCustomerPipeline
{
    /**
     * @return array<string, mixed>
     */
    public static function runForAtelier(int $atelierId): array
    {
        if ($atelierId <= 0 || ! Schema::hasTable('shop_customer_metrics')) {
            return ['ok' => false, 'message' => 'not_ready'];
        }

        if (! ShopFeatureFlags::enabled($atelierId, ShopFeatureFlags::CUSTOMER_CLUB)) {
            return ['ok' => false, 'message' => 'club_disabled'];
        }

        $metrics = CustomerMetricsComputer::computeForAtelier($atelierId);
        $segments = CustomerSegmentAssigner::assignForAtelier($atelierId);
        $actions = SmartActionGenerator::generateForAtelier($atelierId);

        return [
            'ok' => true,
            'atelier_id' => $atelierId,
            'metrics' => $metrics,
            'segments' => $segments,
            'actions' => $actions,
        ];
    }

    /**
     * @return array{ateliers:int,results:array<int,array<string,mixed>>}
     */
    public static function runAllEnabledShops(): array
    {
        $ids = Atelier::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $results = [];
        $count = 0;
        foreach ($ids as $id) {
            if (! ShopFeatureFlags::enabled($id, ShopFeatureFlags::CUSTOMER_CLUB)) {
                continue;
            }
            $results[$id] = self::runForAtelier($id);
            $count++;
        }

        return ['ateliers' => $count, 'results' => $results];
    }
}
