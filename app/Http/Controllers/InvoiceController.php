<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class InvoiceController extends Controller
{
    /**
     * نمایش لیست فاکتورها (همان فروشگاه)
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = Invoice::where('atelier_id', $atelierId)->orderBy('id', 'desc');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->title)) {
                        $q->where('title', 'like', '%'.$searchDataModel->title.'%');
                    }
                    if (isset($searchDataModel->description)) {
                        $q->orWhere('description', 'like', '%'.$searchDataModel->description.'%');
                    }
                    if (isset($searchDataModel->user_name)) {
                        $q->orWhere('user_name', 'like', '%'.$searchDataModel->user_name.'%');
                    }
                } elseif (is_string($searchDataModel)) {
                    $q->where('title', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('description', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('user_name', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        if ($request->has('filter')) {
            if ($request->filter === 'today') {
                $query->whereDate('date', Carbon::today());
            } elseif ($request->filter === 'week') {
                $now = Jalalian::now();
                $dayOfWeek = $now->getDayOfWeek();
                $startOfWeekJalali = Jalalian::now()->subDays($dayOfWeek);
                $endOfWeekJalali = Jalalian::now()->addDays(6 - $dayOfWeek);
                $startOfWeek = $startOfWeekJalali->toCarbon()->startOfDay();
                $endOfWeek = $endOfWeekJalali->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfWeek, $endOfWeek]);
            } elseif ($request->filter === 'month') {
                $now = Jalalian::now();
                $year = $now->getYear();
                $month = $now->getMonth();
                $startOfMonthJalali = new Jalalian($year, $month, 1);
                $startOfMonth = $startOfMonthJalali->toCarbon()->startOfDay();
                $endOfMonthJalali = (new Jalalian($year, $month, 1))->addMonths(1)->subDays(1);
                $endOfMonth = $endOfMonthJalali->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfMonth, $endOfMonth]);
            } elseif ($request->filter === 'year') {
                $now = Jalalian::now();
                $year = $now->getYear();
                $startOfYear = (new Jalalian($year, 1, 1))->toCarbon()->startOfDay();
                $endOfYear = (new Jalalian($year, 12, 29))->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfYear, $endOfYear]);
            } elseif ($request->filter === 'range') {
                if ($request->has('from_date')) {
                    $fromDate = json_decode($request->input('from_date'));
                    $fromCarbon = (new Jalalian($fromDate->year, $fromDate->month, $fromDate->day))->toCarbon()->startOfDay();
                    $query->where('date', '>=', $fromCarbon);
                }
                if ($request->has('to_date')) {
                    $toDate = json_decode($request->input('to_date'));
                    $toCarbon = (new Jalalian($toDate->year, $toDate->month, $toDate->day))->toCarbon()->endOfDay();
                    $query->where('date', '<=', $toCarbon);
                }
            }
        }

        $totalAmount = (clone $query)->sum('amount');

        $perPage = $request->input('per_page', 20);

        $invoices = $query->paginate($perPage);

        $invoices->withPath(url()->current());

        $invoicesArray = $invoices->toArray();
        $invoicesArray['total_amount'] = (float) $totalAmount;

        return response($invoicesArray, 200);
    }

    /**
     * افزودن فاکتور جدید
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت فاکتور فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate([
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $user = $this->shopRequestActor($request);
        if (! $user) {
            return response(['error' => 'کاربر احراز هویت نشده است'], 401);
        }

        $fields['user_name'] = trim($user->name.' '.$user->last_name);

        $fields['date'] = Carbon::now()->format('Y-m-d');
        $fields['atelier_id'] = $atelierId;

        $invoice = Invoice::create($fields);

        return response($invoice, 201);
    }

    /**
     * نمایش جزئیات یک فاکتور
     */
    public function show(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        return response($invoice, 200);
    }

    /**
     * ویرایش اطلاعات فاکتور
     */
    public function update(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        $fields = $request->validate([
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'date' => 'sometimes|required|date',
        ]);

        $invoice->update($fields);

        return response($invoice, 200);
    }

    /**
     * حذف فاکتور
     */
    public function destroy(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        $invoice->delete();

        return response(['message' => 'فاکتور با موفقیت حذف شد'], 200);
    }
}
