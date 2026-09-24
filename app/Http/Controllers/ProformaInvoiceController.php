<?php

namespace App\Http\Controllers;

use App\Models\ProformaInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProformaInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('proforma_invoices')) {
            return response(['data' => []], 200);
        }

        $rows = ProformaInvoice::query()
            ->where('atelier_id', $atelierId)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response(['data' => $rows], 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('proforma_invoices')) {
            return response(['message' => 'جدول پیش‌فاکتور هنوز ساخته نشده است.'], 422);
        }

        $fields = $request->validate([
            'phone' => 'nullable|string|max:20',
            'discount_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1|max:200',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.sale_price' => 'required|numeric|min:0',
            'items.*.purchase_price' => 'nullable|numeric|min:0',
            'items.*.product_id' => 'nullable|integer',
            'items.*.produced_good_id' => 'nullable|integer',
            'items.*.raw_material_id' => 'nullable|integer',
            'items.*.size' => 'nullable|string|max:255',
            'items.*.color' => 'nullable|string|max:255',
        ]);

        $discount = round((float) ($fields['discount_amount'] ?? 0), 2);
        $gross = 0.0;
        $items = [];
        foreach ($fields['items'] as $row) {
            $qty = (float) $row['quantity'];
            $price = round((float) $row['sale_price'], 2);
            $gross += $qty * $price;
            $line = [
                'name' => $row['name'],
                'quantity' => $qty,
                'sale_price' => $price,
                'purchase_price' => round((float) ($row['purchase_price'] ?? 0), 2),
            ];
            foreach (['product_id', 'produced_good_id', 'raw_material_id'] as $key) {
                if (! empty($row[$key])) {
                    $line[$key] = (int) $row[$key];
                }
            }
            if (! empty($row['size'])) {
                $line['size'] = $row['size'];
            }
            if (! empty($row['color'])) {
                $line['color'] = $row['color'];
            }
            if (empty($line['product_id']) && empty($line['produced_good_id']) && empty($line['raw_material_id'])) {
                return response(['message' => 'هر ردیف پیش‌فاکتور باید به یک کالا وصل باشد.'], 422);
            }
            $items[] = $line;
        }

        $proforma = ProformaInvoice::create([
            'atelier_id' => $atelierId,
            'phone' => $fields['phone'] ?? null,
            'discount_amount' => $discount,
            'total_amount' => round(max(0, $gross - $discount), 2),
            'items' => $items,
        ]);

        return response($proforma, 201);
    }

    public function destroy(Request $request, ProformaInvoice $proformaInvoice)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $proformaInvoice->atelier_id !== (int) $atelierId) {
            return response(['message' => 'این پیش‌فاکتور متعلق به فروشگاه شما نیست.'], 403);
        }
        $proformaInvoice->delete();

        return response(['message' => 'پیش‌فاکتور حذف شد'], 200);
    }
}
