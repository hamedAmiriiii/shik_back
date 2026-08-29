<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\UserShiksho;
use App\Services\ShopBeneficiaryService;
use App\Services\ShopStaffAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BeneficiaryController extends Controller
{
    /**
     * لیست کاربران فروشگاه با جمع خرید از آن‌ها و بدهی به آن‌ها.
     * GET /api/beneficiaries?q=&per_page=20&only_with_docs=1
     */
    public function index(Request $request)
    {
        $this->assertCanViewBeneficiaries($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = UserShiksho::query()->where('atelier_id', $atelierId);

        $q = trim((string) $request->input('q', $request->input('search', '')));
        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('phone', 'like', '%'.$q.'%');
                if (Schema::hasColumn('user_shiksho', 'name')) {
                    $inner->orWhere('name', 'like', '%'.$q.'%');
                }
            });
        }

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if (is_object($searchDataModel)) {
            if (! empty($searchDataModel->phone)) {
                $query->where('phone', 'like', '%'.$searchDataModel->phone.'%');
            }
            if (! empty($searchDataModel->name) && Schema::hasColumn('user_shiksho', 'name')) {
                $query->where('name', 'like', '%'.$searchDataModel->name.'%');
            }
        }

        $invoiceAgg = $this->documentAggregateSubquery('invoices', $atelierId);
        $expenseAgg = $this->documentAggregateSubquery('expenses', $atelierId);
        if ($invoiceAgg) {
            $query->leftJoinSub($invoiceAgg, 'inv_agg', 'inv_agg.beneficiary_id', '=', 'user_shiksho.id');
        }
        if ($expenseAgg) {
            $query->leftJoinSub($expenseAgg, 'exp_agg', 'exp_agg.beneficiary_id', '=', 'user_shiksho.id');
        }

        $purchasedSql = $this->sumCoalesceSql($invoiceAgg, $expenseAgg, 'total');
        $unpaidSql = $this->sumCoalesceSql($invoiceAgg, $expenseAgg, 'unpaid');

        $query->select('user_shiksho.*')
            ->selectRaw($purchasedSql.' as purchased_total')
            ->selectRaw($unpaidSql.' as unpaid_total')
            ->selectRaw($this->sumCoalesceSql($invoiceAgg, $expenseAgg, 'paid').' as paid_total')
            ->selectRaw(($invoiceAgg ? 'COALESCE(inv_agg.total, 0)' : '0').' as invoice_total')
            ->selectRaw(($expenseAgg ? 'COALESCE(exp_agg.total, 0)' : '0').' as expense_total')
            ->selectRaw(($invoiceAgg ? 'COALESCE(inv_agg.doc_count, 0)' : '0').' as invoice_count')
            ->selectRaw(($expenseAgg ? 'COALESCE(exp_agg.doc_count, 0)' : '0').' as expense_count');

        if ($request->boolean('only_with_docs')) {
            $query->whereRaw('('.$purchasedSql.') > 0');
        }

        $query->orderByRaw($unpaidSql.' DESC')
            ->orderByRaw($purchasedSql.' DESC')
            ->orderBy('user_shiksho.id', 'desc');

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function (UserShiksho $user) {
            ShopBeneficiaryService::hideSensitive($user);
            $user->purchased_total = round((float) $user->purchased_total, 2);
            $user->unpaid_total = round((float) $user->unpaid_total, 2);
            $user->paid_total = round((float) $user->paid_total, 2);
            $user->invoice_total = round((float) $user->invoice_total, 2);
            $user->expense_total = round((float) $user->expense_total, 2);
            $user->invoice_count = (int) $user->invoice_count;
            $user->expense_count = (int) $user->expense_count;

            return $user;
        });

        return response($page, 200);
    }

    /**
     * جزئیات یک ذینفع: جمع خرید، بدهی، فاکتورها و هزینه‌ها.
     * GET /api/beneficiaries/{id}
     */
    public function show(Request $request, $id)
    {
        $this->assertCanViewBeneficiaries($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $user = ShopBeneficiaryService::findForShop($atelierId, (int) $id);
        if (! $user) {
            return response()->json(['message' => 'ذینفع یافت نشد.'], 404);
        }

        ShopBeneficiaryService::hideSensitive($user);
        $totals = ShopBeneficiaryService::totalsFor($user);

        $invoices = collect();
        if (ShopBeneficiaryService::supports('invoices')) {
            $invoices = Invoice::query()
                ->where('atelier_id', $atelierId)
                ->where('beneficiary_id', $user->id)
                ->orderByDesc('id')
                ->get();
            $invoices->each(function (Invoice $invoice) {
                $this->attachPaymentAccount($invoice);
            });
        }

        $expenses = collect();
        if (ShopBeneficiaryService::supports('expenses')) {
            $expenses = Expense::query()
                ->where('atelier_id', $atelierId)
                ->where('beneficiary_id', $user->id)
                ->orderByDesc('id')
                ->get();
            $expenses->each(function (Expense $expense) {
                $this->attachPaymentAccount($expense);
            });
        }

        return response([
            'beneficiary' => $user,
            'totals' => $totals,
            'invoices' => $invoices,
            'expenses' => $expenses,
        ], 200);
    }

    protected function assertCanViewBeneficiaries(Request $request): void
    {
        $user = $request->user();
        if (ShopStaffAccess::isOwner($user) || ShopStaffAccess::isPlatformAdmin($user)) {
            return;
        }
        if (
            ShopStaffAccess::can($user, 'invoices')
            || ShopStaffAccess::can($user, 'expenses')
            || ShopStaffAccess::can($user, 'customers')
        ) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'شما اجازهٔ مشاهده ذینفعان را ندارید.',
            'error' => 'شما اجازهٔ مشاهده ذینفعان را ندارید.',
        ], 403));
    }

    /**
     * @return \Illuminate\Database\Query\Builder|null
     */
    protected function documentAggregateSubquery(string $table, int $atelierId)
    {
        if (! ShopBeneficiaryService::supports($table)) {
            return null;
        }

        $unpaid = Schema::hasColumn($table, 'payment_status')
            ? 'SUM(CASE WHEN payment_status = \'unpaid\' THEN amount ELSE 0 END)'
            : '0';
        $paid = Schema::hasColumn($table, 'payment_status')
            ? 'SUM(CASE WHEN payment_status = \'unpaid\' THEN 0 ELSE amount END)'
            : 'SUM(amount)';

        return DB::table($table)
            ->select('beneficiary_id')
            ->selectRaw('SUM(amount) as total')
            ->selectRaw($unpaid.' as unpaid')
            ->selectRaw($paid.' as paid')
            ->selectRaw('COUNT(*) as doc_count')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('beneficiary_id')
            ->groupBy('beneficiary_id');
    }

    protected function sumCoalesceSql($invoiceAgg, $expenseAgg, string $column): string
    {
        $inv = $invoiceAgg ? 'COALESCE(inv_agg.'.$column.', 0)' : '0';
        $exp = $expenseAgg ? 'COALESCE(exp_agg.'.$column.', 0)' : '0';

        return '('.$inv.' + '.$exp.')';
    }
}
