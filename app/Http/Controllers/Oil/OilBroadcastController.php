<?php

namespace App\Http\Controllers\Oil;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Http\Controllers\Controller;
use App\Models\OilVisit;
use App\Services\ShopSmsQuotaService;
use App\Support\ProjectType;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OilBroadcastController extends Controller
{
    public function customers(Request $request)
    {
        $atelierId = $this->oilAtelierId($request);

        $rows = OilVisit::query()
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('phone', DB::raw('COUNT(*) as total_purchases'), DB::raw('MAX(id) as last_id'))
            ->groupBy('phone')
            ->orderByDesc('last_id')
            ->get();

        $customers = $rows->map(function ($item) {
            return [
                'phone' => (string) $item->phone,
                'name' => null,
                'total_purchases' => (int) ($item->total_purchases ?? 0),
                'total_spent' => 0,
            ];
        })->values();

        return response(['customers' => $customers], 200);
    }

    public function message(Request $request)
    {
        $atelierId = $this->oilAtelierId($request);
        $request->validate([
            'message' => 'required|string|max:1000',
            'phones' => 'required|array|min:1',
            'phones.*' => 'required|string|digits:11',
        ]);

        $phones = array_values(array_unique($request->input('phones')));
        $fullMessage = SmsTools::shopSmsBrand($atelierId)."\n".$request->input('message');

        $partsEach = ShopSmsQuotaService::countSmsParts($fullMessage);
        $totalParts = $partsEach * count($phones);
        $balance = ShopSmsQuotaService::getBalance($atelierId);
        if ($balance < $totalParts) {
            throw new InsufficientShopSmsQuotaException($totalParts, $balance);
        }

        $successCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($phones as $phone) {
            try {
                $result = SmsTools::sendShopSms(
                    $phone,
                    $fullMessage,
                    null,
                    null,
                    'broadcast',
                    $atelierId
                );
                $successCount++;
                $results[] = [
                    'phone' => $phone,
                    'status' => 'success',
                    'result' => $result,
                ];
            } catch (InsufficientShopSmsQuotaException $e) {
                throw $e;
            } catch (\Exception $e) {
                $failedCount++;
                $results[] = [
                    'phone' => $phone,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response([
            'message' => 'پیام به مشتریان ارسال شد',
            'total_customers' => count($phones),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ], 200);
    }

    private function oilAtelierId(Request $request): int
    {
        if (! Schema::hasTable('oil_visits')) {
            abort(response()->json(['message' => 'جداول تعویض روغن هنوز ساخته نشده‌اند.'], 503));
        }

        $user = $request->user();
        if (! $user || ProjectType::normalize($user->project_type) !== ProjectType::OIL) {
            abort(response()->json(['message' => 'دسترسی ندارید.'], 403));
        }
        if (! $user->atelier_id) {
            abort(response()->json(['message' => 'حساب به تعویض روغنی متصل نیست.'], 422));
        }

        return $this->shopAtelierIdOrAbort($request);
    }
}
