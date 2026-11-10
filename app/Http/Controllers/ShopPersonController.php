<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use App\Models\User;
use App\Models\UserShiksho;
use App\Services\ShopBeneficiaryService;
use App\Services\ShopStaffAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopPersonController extends Controller
{
    /**
     * اشخاص درگیر فروشگاه: خریدار، یا طرف فاکتور و هزینه.
     * GET /api/shop-people?q=&role=all|buyer|beneficiary&page=&per_page=
     */
    public function index(Request $request)
    {
        $this->assertCanView($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $people = $this->involvedPhones($atelierId);
        $query = DB::query()->fromSub($people, 'p');

        $hasUsers = Schema::hasTable('user_shiksho');
        $hasName = $hasUsers && Schema::hasColumn('user_shiksho', 'name');
        $hasBirth = $hasUsers && Schema::hasColumn('user_shiksho', 'birth_date');

        if ($hasUsers) {
            $query->leftJoin('user_shiksho as u', function ($join) use ($atelierId) {
                $join->on('u.phone', '=', 'p.phone')
                    ->where('u.atelier_id', '=', $atelierId);
            });
        }

        $purchaseAgg = $this->purchaseAggregate($atelierId);
        if ($purchaseAgg) {
            $query->leftJoinSub($purchaseAgg, 'buy', 'buy.phone', '=', 'p.phone');
        }

        $invoiceAgg = $this->beneficiaryAggregate('invoices', $atelierId);
        if ($invoiceAgg && $hasUsers) {
            $query->leftJoinSub($invoiceAgg, 'inv', 'inv.beneficiary_id', '=', 'u.id');
        }
        $expenseAgg = $this->beneficiaryAggregate('expenses', $atelierId);
        if ($expenseAgg && $hasUsers) {
            $query->leftJoinSub($expenseAgg, 'exp', 'exp.beneficiary_id', '=', 'u.id');
        }

        $purchaseCount = $purchaseAgg ? 'COALESCE(buy.purchase_count, 0)' : '0';
        $purchaseTotal = $purchaseAgg ? 'COALESCE(buy.purchase_total, 0)' : '0';
        $invoiceCount = $invoiceAgg && $hasUsers ? 'COALESCE(inv.doc_count, 0)' : '0';
        $expenseCount = $expenseAgg && $hasUsers ? 'COALESCE(exp.doc_count, 0)' : '0';

        $query->selectRaw(($hasUsers ? 'u.id' : 'NULL').' as id')
            ->addSelect('p.phone')
            ->selectRaw(($hasName ? 'u.name' : 'NULL').' as name')
            ->selectRaw(($hasBirth ? 'u.birth_date' : 'NULL').' as birth_date')
            ->selectRaw($purchaseCount.' as purchase_count')
            ->selectRaw($purchaseTotal.' as purchase_total')
            ->selectRaw($invoiceCount.' as invoice_count')
            ->selectRaw($expenseCount.' as expense_count');

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $digits = $this->digits($q);
            $query->where(function ($inner) use ($q, $digits, $hasName) {
                $inner->where('p.phone', 'like', '%'.$digits.'%');
                if ($digits !== $q) {
                    $inner->orWhere('p.phone', 'like', '%'.$q.'%');
                }
                if ($hasName) {
                    $inner->orWhere('u.name', 'like', '%'.$q.'%');
                }
            });
        }

        $role = (string) $request->input('role', 'all');
        if ($role === 'buyer') {
            $query->whereRaw($purchaseCount.' > 0');
        } elseif ($role === 'beneficiary') {
            $query->whereRaw('('.$invoiceCount.' + '.$expenseCount.') > 0');
        }

        if ($hasName) {
            $query->orderByRaw('u.name IS NULL')->orderBy('u.name');
        }
        $query->orderBy('p.phone');

        $perPage = max(1, min(100, (int) $request->input('per_page', 30)));
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function ($row) {
            $birth = $row->birth_date ? substr((string) $row->birth_date, 0, 10) : null;
            $purchaseCount = (int) $row->purchase_count;
            $invoiceCount = (int) $row->invoice_count;
            $expenseCount = (int) $row->expense_count;

            return [
                'id' => $row->id ? (int) $row->id : null,
                'name' => $row->name ? trim((string) $row->name) : null,
                'phone' => (string) $row->phone,
                'birth_date' => $birth,
                'purchase_count' => $purchaseCount,
                'purchase_total' => round((float) $row->purchase_total, 2),
                'invoice_count' => $invoiceCount,
                'expense_count' => $expenseCount,
                'is_buyer' => $purchaseCount > 0,
                'is_beneficiary' => ($invoiceCount + $expenseCount) > 0,
            ];
        });

        return response($page, 200);
    }

    /**
     * ویرایش نام، موبایل و تاریخ تولد.
     * PUT /api/shop-people
     */
    public function update(Request $request)
    {
        $this->assertCanView($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $currentPhone = $this->digits((string) $request->input('phone', ''));
        $nextPhone = $this->digits((string) ($request->input('new_phone', $currentPhone)));
        if ($request->input('birth_date') === '') {
            $request->merge(['birth_date' => null]);
        }
        $request->merge([
            'phone' => $currentPhone,
            'new_phone' => $nextPhone,
        ]);

        $validated = $request->validate([
            'phone' => 'required|digits:11',
            'new_phone' => 'required|digits:11',
            'name' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
        ]);

        if (! $this->phoneIsInvolved($atelierId, $validated['phone'])) {
            return response()->json(['message' => 'این شخص در فروشگاه پیدا نشد.'], 404);
        }

        $name = trim((string) ($validated['name'] ?? ''));
        $name = $name !== '' ? preg_replace('/\s+/u', ' ', $name) : null;
        $birthDate = $validated['birth_date'] ?? null;
        $newPhone = $validated['new_phone'];
        $oldPhone = $validated['phone'];

        if ($newPhone !== $oldPhone && $this->phoneTakenBySomeoneElse($atelierId, $oldPhone, $newPhone)) {
            return response()->json(['message' => 'این شماره برای شخص دیگری ثبت شده است.'], 422);
        }

        $user = DB::transaction(function () use ($atelierId, $oldPhone, $newPhone, $name, $birthDate) {
            if ($newPhone !== $oldPhone) {
                $this->movePhone($atelierId, $oldPhone, $newPhone);
            }

            if (! Schema::hasTable('user_shiksho')) {
                return null;
            }

            $user = UserShiksho::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $oldPhone)
                ->first();

            $updates = [];
            if (Schema::hasColumn('user_shiksho', 'name')) {
                $updates['name'] = $name;
            }
            if (Schema::hasColumn('user_shiksho', 'birth_date')) {
                $updates['birth_date'] = $birthDate;
            }
            if ($newPhone !== $oldPhone) {
                $updates['phone'] = $newPhone;
            }

            if (! $user) {
                $user = new UserShiksho([
                    'phone' => $newPhone,
                    'credit' => 0,
                    'installment_credit' => 0,
                    'credit_last_updated_at' => now(),
                ]);
                $user->atelier_id = $atelierId;
            }

            $user->fill($updates);
            $user->save();

            return $user;
        });

        return response([
            'message' => 'مشخصات شخص ذخیره شد.',
            'person' => [
                'id' => $user?->id,
                'name' => $name,
                'phone' => $newPhone,
                'birth_date' => $birthDate,
            ],
        ], 200);
    }

    protected function involvedPhones(int $atelierId)
    {
        $parts = [];

        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'phone')) {
            $parts[] = DB::table('purchases')
                ->select('phone')
                ->where('atelier_id', $atelierId)
                ->whereNotNull('phone')
                ->where('phone', '!=', '');
        }

        if (Schema::hasTable('user_shiksho')) {
            $beneficiaryIds = $this->beneficiaryIdQuery($atelierId);
            if ($beneficiaryIds) {
                $parts[] = DB::table('user_shiksho')
                    ->select('phone')
                    ->where('atelier_id', $atelierId)
                    ->whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->whereIn('id', $beneficiaryIds);
            }
        }

        if ($parts === []) {
            return DB::query()->selectRaw("'' as phone")->whereRaw('1 = 0');
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->union($part);
        }

        return $union;
    }

    protected function beneficiaryIdQuery(int $atelierId)
    {
        $queries = [];
        foreach (['invoices', 'expenses'] as $table) {
            if (! ShopBeneficiaryService::supports($table)) {
                continue;
            }
            $queries[] = DB::table($table)
                ->select('beneficiary_id')
                ->where('atelier_id', $atelierId)
                ->whereNotNull('beneficiary_id');
        }
        if ($queries === []) {
            return null;
        }
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->union($query);
        }

        return $union;
    }

    protected function purchaseAggregate(int $atelierId)
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasColumn('purchases', 'phone')) {
            return null;
        }

        return DB::table('purchases')
            ->select('phone')
            ->selectRaw('COUNT(*) as purchase_count')
            ->selectRaw('SUM(total_amount) as purchase_total')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->groupBy('phone');
    }

    protected function beneficiaryAggregate(string $table, int $atelierId)
    {
        if (! ShopBeneficiaryService::supports($table)) {
            return null;
        }

        return DB::table($table)
            ->select('beneficiary_id')
            ->selectRaw('COUNT(*) as doc_count')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('beneficiary_id')
            ->groupBy('beneficiary_id');
    }

    protected function phoneIsInvolved(int $atelierId, string $phone): bool
    {
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'phone')) {
            $bought = Purchase::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $phone)
                ->exists();
            if ($bought) {
                return true;
            }
        }

        if (! Schema::hasTable('user_shiksho')) {
            return false;
        }

        $user = UserShiksho::query()
            ->where('atelier_id', $atelierId)
            ->where('phone', $phone)
            ->first();
        if (! $user) {
            return false;
        }

        foreach (['invoices', 'expenses'] as $table) {
            if (! ShopBeneficiaryService::supports($table)) {
                continue;
            }
            $named = DB::table($table)
                ->where('atelier_id', $atelierId)
                ->where('beneficiary_id', $user->id)
                ->exists();
            if ($named) {
                return true;
            }
        }

        return false;
    }

    protected function phoneTakenBySomeoneElse(int $atelierId, string $oldPhone, string $newPhone): bool
    {
        if (Schema::hasTable('user_shiksho')) {
            $taken = UserShiksho::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $newPhone)
                ->where('phone', '!=', $oldPhone)
                ->exists();
            if ($taken) {
                return true;
            }
        }

        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'phone')) {
            return Purchase::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $newPhone)
                ->exists();
        }

        return false;
    }

    protected function movePhone(int $atelierId, string $oldPhone, string $newPhone): void
    {
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'phone')) {
            Purchase::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $oldPhone)
                ->update(['phone' => $newPhone]);
        }

        if (Schema::hasTable('purchase_item_returns') && Schema::hasColumn('purchase_item_returns', 'phone')) {
            $returns = PurchaseItemReturn::query()->where('phone', $oldPhone);
            if (Schema::hasColumn('purchase_item_returns', 'atelier_id')) {
                $returns->where('atelier_id', $atelierId);
            }
            $returns->update(['phone' => $newPhone]);
        }

        if (Schema::hasTable('purchased_products') && Schema::hasColumn('purchased_products', 'phone') && Schema::hasTable('purchases')) {
            $purchaseIds = Purchase::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $newPhone)
                ->pluck('id');
            if ($purchaseIds->isNotEmpty()) {
                DB::table('purchased_products')
                    ->whereIn('purchase_id', $purchaseIds)
                    ->where('phone', $oldPhone)
                    ->update(['phone' => $newPhone]);
            }
        }
    }

    protected function digits(string $raw): string
    {
        return strtr(trim($raw), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    protected function assertCanView(Request $request): void
    {
        $actor = $request->user();
        $user = $actor instanceof User ? $actor : null;
        if (ShopStaffAccess::isOwner($user) || ShopStaffAccess::isPlatformAdmin($user)) {
            return;
        }
        foreach (['invoices', 'expenses', 'customers', 'pos'] as $key) {
            if (ShopStaffAccess::can($user, $key)) {
                return;
            }
        }

        throw new HttpResponseException(response()->json([
            'message' => 'شما اجازهٔ مشاهده اشخاص را ندارید.',
        ], 403));
    }
}
