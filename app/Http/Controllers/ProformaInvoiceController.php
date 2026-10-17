<?php

namespace App\Http\Controllers;

use App\Models\ProformaInvoice;
use App\Models\UserShiksho;
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

        $search = trim((string) $request->input('search', ''));
        $hasNameColumn = Schema::hasColumn('proforma_invoices', 'customer_name');
        $query = ProformaInvoice::query()->where('atelier_id', $atelierId);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like, $atelierId, $hasNameColumn) {
                $q->where('phone', 'like', $like);
                if ($hasNameColumn) {
                    $q->orWhere('customer_name', 'like', $like);
                }
                if (Schema::hasTable('user_shiksho') && Schema::hasColumn('user_shiksho', 'name')) {
                    $q->orWhereIn('phone', function ($sub) use ($atelierId, $like) {
                        $sub->select('phone')
                            ->from('user_shiksho')
                            ->where('atelier_id', $atelierId)
                            ->where('name', 'like', $like);
                    });
                }
            });
        }

        $rows = $query->orderByDesc('id')->limit(200)->get();

        return response(['data' => $this->withCustomerNames($rows, $atelierId, $hasNameColumn)], 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('proforma_invoices')) {
            return response(['message' => 'جدول پیش‌فاکتور هنوز ساخته نشده است.'], 422);
        }

        $fields = $request->validate([
            'phone' => 'nullable|string|max:20',
            'customer_name' => 'nullable|string|max:255',
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

        $payload = [
            'atelier_id' => $atelierId,
            'phone' => $fields['phone'] ?? null,
            'discount_amount' => $discount,
            'total_amount' => round(max(0, $gross - $discount), 2),
            'items' => $items,
        ];
        if (Schema::hasColumn('proforma_invoices', 'customer_name')) {
            $name = trim((string) ($fields['customer_name'] ?? ''));
            $payload['customer_name'] = $name !== '' ? $name : null;
        }

        $proforma = ProformaInvoice::create($payload);

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

    /**
     * @param  \Illuminate\Support\Collection<int, ProformaInvoice>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function withCustomerNames($rows, int $atelierId, bool $hasNameColumn): array
    {
        $phones = $rows
            ->pluck('phone')
            ->filter(fn ($phone) => is_string($phone) && $phone !== '')
            ->unique()
            ->values()
            ->all();

        $directory = [];
        if ($phones !== [] && Schema::hasTable('user_shiksho') && Schema::hasColumn('user_shiksho', 'name')) {
            $directory = UserShiksho::query()
                ->where('atelier_id', $atelierId)
                ->whereIn('phone', $phones)
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->pluck('name', 'phone')
                ->all();
        }

        return $rows->map(function (ProformaInvoice $row) use ($directory, $hasNameColumn) {
            $data = $row->toArray();
            $stored = $hasNameColumn ? trim((string) ($data['customer_name'] ?? '')) : '';
            $phone = is_string($data['phone'] ?? null) ? $data['phone'] : '';
            $lookedUp = $phone !== '' ? trim((string) ($directory[$phone] ?? '')) : '';
            $data['customer_name'] = $stored !== '' ? $stored : ($lookedUp !== '' ? $lookedUp : null);

            return $data;
        })->values()->all();
    }
}
