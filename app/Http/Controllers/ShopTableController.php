<?php

namespace App\Http\Controllers;

use App\Models\ShopTable;
use Illuminate\Http\Request;

class ShopTableController extends Controller
{
    private function atelierId(): int
    {
        return (int) auth()->user()->atelier_id;
    }

    /**
     * لیست میزهای فروشگاه
     */
    public function index()
    {
        $tables = ShopTable::where('atelier_id', $this->atelierId())
            ->orderBy('table_number')
            ->get();

        return response()->json($tables);
    }

    /**
     * ایجاد میز جدید
     */
    public function store(Request $request)
    {
        $atelierId = $this->atelierId();

        $request->validate([
            'table_number' => 'required|integer|min:1|max:999',
            'label' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $exists = ShopTable::where('atelier_id', $atelierId)
            ->where('table_number', $request->table_number)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'این شماره میز قبلاً ثبت شده است'], 422);
        }

        $table = ShopTable::create([
            'atelier_id' => $atelierId,
            'table_number' => $request->table_number,
            'label' => $request->label,
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json($table, 201);
    }

    /**
     * ویرایش میز
     */
    public function update(Request $request, ShopTable $shopTable)
    {
        $this->authorizeTable($shopTable);

        $request->validate([
            'label' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $shopTable->update($request->only(['label', 'is_active']));

        return response()->json($shopTable);
    }

    /**
     * حذف میز
     */
    public function destroy(ShopTable $shopTable)
    {
        $this->authorizeTable($shopTable);
        $shopTable->delete();

        return response()->json(['message' => 'میز حذف شد'], 200);
    }

    /**
     * ایجاد خودکار میزهای پیش‌فرض (۱ تا N)
     */
    public function createDefaults(Request $request)
    {
        $atelierId = $this->atelierId();

        $request->validate([
            'count' => 'required|integer|min:1|max:50',
        ]);

        $count = $request->count;
        $created = [];

        for ($i = 1; $i <= $count; $i++) {
            $table = ShopTable::firstOrCreate(
                ['atelier_id' => $atelierId, 'table_number' => $i],
                ['is_active' => true]
            );
            $created[] = $table;
        }

        return response()->json([
            'message' => "{$count} میز با موفقیت ایجاد شد",
            'tables' => $created,
        ]);
    }

    private function authorizeTable(ShopTable $table): void
    {
        if ($table->atelier_id !== $this->atelierId()) {
            abort(response()->json([
                'message' => 'شما به این میز دسترسی ندارید.',
                'error' => 'شما به این میز دسترسی ندارید.',
                'permission' => 'shop_tables',
                'permission_label' => \App\Services\ShopPermissionCatalog::labelFor('shop_tables'),
            ], 403));
        }
    }
}
