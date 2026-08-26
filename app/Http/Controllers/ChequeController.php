<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class ChequeController extends Controller
{
    /**
     * لیست چک‌ها
     * GET /api/cheques?type=issued|received&status=pending
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = Cheque::where('atelier_id', $atelierId)
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'desc');

        if ($request->filled('type') && in_array($request->input('type'), [
            Cheque::TYPE_ISSUED,
            Cheque::TYPE_RECEIVED,
        ], true)) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status') && in_array($request->input('status'), [
            Cheque::STATUS_PENDING,
            Cheque::STATUS_CLEARED,
            Cheque::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->boolean('available_for_sale')) {
            $query->where('type', Cheque::TYPE_RECEIVED)
                ->where('status', Cheque::STATUS_PENDING)
                ->whereNull('purchase_id');
        }

        if ($request->filled('cheque_number')) {
            $query->where('cheque_number', 'like', '%'.$request->input('cheque_number').'%');
        }

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->cheque_number)) {
                        $q->where('cheque_number', 'like', '%'.$searchDataModel->cheque_number.'%');
                    }
                    if (isset($searchDataModel->payee)) {
                        $q->orWhere('payee', 'like', '%'.$searchDataModel->payee.'%');
                    }
                    if (isset($searchDataModel->bank_name)) {
                        $q->orWhere('bank_name', 'like', '%'.$searchDataModel->bank_name.'%');
                    }
                    if (isset($searchDataModel->title)) {
                        $q->orWhere('title', 'like', '%'.$searchDataModel->title.'%');
                    }
                } elseif (is_string($searchDataModel)) {
                    $q->where('cheque_number', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('payee', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('bank_name', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('title', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        if ($request->has('filter')) {
            if ($request->filter === 'today') {
                $query->whereDate('due_date', Carbon::today('Asia/Tehran'));
            } elseif ($request->filter === 'week') {
                $now = Jalalian::now();
                $dayOfWeek = $now->getDayOfWeek();
                $startOfWeek = Jalalian::now()->subDays($dayOfWeek)->toCarbon()->startOfDay();
                $endOfWeek = Jalalian::now()->addDays(6 - $dayOfWeek)->toCarbon()->endOfDay();
                $query->whereBetween('due_date', [$startOfWeek, $endOfWeek]);
            } elseif ($request->filter === 'month') {
                $now = Jalalian::now();
                $startOfMonth = (new Jalalian($now->getYear(), $now->getMonth(), 1))->toCarbon()->startOfDay();
                $endOfMonth = (new Jalalian($now->getYear(), $now->getMonth(), 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();
                $query->whereBetween('due_date', [$startOfMonth, $endOfMonth]);
            } elseif ($request->filter === 'range') {
                if ($request->has('from_date')) {
                    $fromDate = json_decode($request->input('from_date'));
                    $fromCarbon = (new Jalalian($fromDate->year, $fromDate->month, $fromDate->day))->toCarbon()->startOfDay();
                    $query->where('due_date', '>=', $fromCarbon);
                }
                if ($request->has('to_date')) {
                    $toDate = json_decode($request->input('to_date'));
                    $toCarbon = (new Jalalian($toDate->year, $toDate->month, $toDate->day))->toCarbon()->endOfDay();
                    $query->where('due_date', '<=', $toCarbon);
                }
            }
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        return response($query->paginate($perPage), 200);
    }

    /**
     * چک‌های در انتظار تا N روز آینده
     * GET /api/cheques/upcoming?days=7&type=issued|received
     */
    public function upcoming(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $days = max(1, min(90, (int) $request->input('days', 7)));
        $today = Carbon::today('Asia/Tehran');
        $until = $today->copy()->addDays($days);

        $query = Cheque::where('atelier_id', $atelierId)
            ->where('status', Cheque::STATUS_PENDING)
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $until->toDateString());

        if ($request->filled('type') && in_array($request->input('type'), [
            Cheque::TYPE_ISSUED,
            Cheque::TYPE_RECEIVED,
        ], true)) {
            $query->where('type', $request->input('type'));
        }

        $cheques = $query->orderBy('due_date', 'asc')->orderBy('id', 'asc')->get();

        return response([
            'days' => $days,
            'from_date' => Jalalian::fromCarbon($today)->format('Y-m-d'),
            'to_date' => Jalalian::fromCarbon($until)->format('Y-m-d'),
            'count' => $cheques->count(),
            'total_amount' => (float) $cheques->sum('amount'),
            'cheques' => $cheques,
        ], 200);
    }

    /**
     * ثبت چک (صادره یا دریافتی)
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت چک فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate([
            'type' => 'required|in:issued,received',
            'cheque_number' => 'required|string|max:64',
            'bank_name' => 'nullable|string|max:255',
            'payee' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
            'title' => 'nullable|string|max:255',
            'expense_type' => 'nullable|in:جاری,سرمایه',
            'note' => 'nullable|string',
            'issue_date' => 'nullable|array',
            'issue_date.year' => 'required_with:issue_date|integer',
            'issue_date.month' => 'required_with:issue_date|integer|min:1|max:12',
            'issue_date.day' => 'required_with:issue_date|integer|min:1|max:31',
            'due_date' => 'required|array',
            'due_date.year' => 'required|integer',
            'due_date.month' => 'required|integer|min:1|max:12',
            'due_date.day' => 'required|integer|min:1|max:31',
        ]);

        $user = $this->shopRequestActor($request);
        if (!$user) {
            return response(['error' => 'کاربر احراز هویت نشده است'], 401);
        }

        $dueDate = (new Jalalian(
            (int) $fields['due_date']['year'],
            (int) $fields['due_date']['month'],
            (int) $fields['due_date']['day']
        ))->toCarbon()->format('Y-m-d');

        $issueDate = null;
        if (!empty($fields['issue_date'])) {
            $issueDate = (new Jalalian(
                (int) $fields['issue_date']['year'],
                (int) $fields['issue_date']['month'],
                (int) $fields['issue_date']['day']
            ))->toCarbon()->format('Y-m-d');
        }

        $cheque = Cheque::create([
            'atelier_id' => $atelierId,
            'type' => $fields['type'],
            'cheque_number' => $fields['cheque_number'],
            'bank_name' => $fields['bank_name'] ?? null,
            'payee' => $fields['payee'] ?? null,
            'amount' => $fields['amount'],
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'title' => $fields['title'] ?? null,
            'expense_type' => $fields['expense_type'] ?? 'جاری',
            'status' => Cheque::STATUS_PENDING,
            'user_name' => trim($user->name.' '.$user->last_name),
            'note' => $fields['note'] ?? null,
        ]);

        return response($cheque, 201);
    }

    public function show(Request $request, Cheque $cheque)
    {
        $this->assertModelBelongsToStaffAtelier($request, $cheque);

        return response($cheque->load(['expense', 'income', 'purchase']), 200);
    }

    /**
     * وصول چک → صادره=هزینه | دریافتی=درآمد
     * POST /api/cheques/{cheque}/clear
     * body اختیاری: clear_date {year,month,day} شمسی — پیش‌فرض امروز
     */
    public function clear(Request $request, Cheque $cheque)
    {
        $this->assertModelBelongsToStaffAtelier($request, $cheque);

        $fields = $request->validate([
            'clear_date' => 'nullable|array',
            'clear_date.year' => 'required_with:clear_date|integer',
            'clear_date.month' => 'required_with:clear_date|integer|min:1|max:12',
            'clear_date.day' => 'required_with:clear_date|integer|min:1|max:31',
        ]);

        $clearDate = null;
        if (!empty($fields['clear_date'])) {
            $clearDate = (new Jalalian(
                (int) $fields['clear_date']['year'],
                (int) $fields['clear_date']['month'],
                (int) $fields['clear_date']['day']
            ))->toCarbon()->format('Y-m-d');
        }

        try {
            $cleared = $cheque->clear($clearDate);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = $cleared->type === Cheque::TYPE_RECEIVED
            ? 'چک دریافتی وصول و به درآمد اضافه شد.'
            : 'چک صادره وصول و به هزینه اضافه شد.';

        return response([
            'message' => $message,
            'cheque' => $cleared,
        ], 200);
    }

    /**
     * برگشت وصول چک (حذف هزینه/درآمد و بازگشت به در انتظار)
     * POST /api/cheques/{cheque}/unclear
     */
    public function unclear(Request $request, Cheque $cheque)
    {
        $this->assertModelBelongsToStaffAtelier($request, $cheque);

        try {
            $reverted = $cheque->unclear();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = $reverted->type === Cheque::TYPE_RECEIVED
            ? 'وصول چک دریافتی برگشت داده شد و درآمد حذف شد.'
            : 'وصول چک صادره برگشت داده شد و هزینه حذف شد.';

        return response([
            'message' => $message,
            'cheque' => $reverted,
        ], 200);
    }

    public function update(Request $request, Cheque $cheque)
    {
        $this->assertModelBelongsToStaffAtelier($request, $cheque);

        if ($cheque->status !== Cheque::STATUS_PENDING) {
            return response()->json([
                'message' => 'فقط چک‌های در انتظار وصول قابل ویرایش هستند.',
            ], 422);
        }

        if ($cheque->purchase_id) {
            return response()->json([
                'message' => 'چکی که به فروش وصل شده فقط از طریق وصول قابل تغییر وضعیت است. مبلغ/اطلاعات قابل ویرایش نیست.',
            ], 422);
        }

        $fields = $request->validate([
            'type' => 'sometimes|required|in:issued,received',
            'cheque_number' => 'sometimes|required|string|max:64',
            'bank_name' => 'nullable|string|max:255',
            'payee' => 'nullable|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'nullable|string|max:255',
            'expense_type' => 'sometimes|required|in:جاری,سرمایه',
            'note' => 'nullable|string',
            'issue_date' => 'nullable|array',
            'issue_date.year' => 'required_with:issue_date|integer',
            'issue_date.month' => 'required_with:issue_date|integer|min:1|max:12',
            'issue_date.day' => 'required_with:issue_date|integer|min:1|max:31',
            'due_date' => 'sometimes|required|array',
            'due_date.year' => 'required_with:due_date|integer',
            'due_date.month' => 'required_with:due_date|integer|min:1|max:12',
            'due_date.day' => 'required_with:due_date|integer|min:1|max:31',
            'status' => 'sometimes|required|in:pending,cancelled',
        ]);

        $update = collect($fields)->except(['issue_date', 'due_date'])->all();

        if (array_key_exists('issue_date', $fields)) {
            if ($fields['issue_date'] === null) {
                $update['issue_date'] = null;
            } else {
                $update['issue_date'] = (new Jalalian(
                    (int) $fields['issue_date']['year'],
                    (int) $fields['issue_date']['month'],
                    (int) $fields['issue_date']['day']
                ))->toCarbon()->format('Y-m-d');
            }
        }

        if (isset($fields['due_date'])) {
            $update['due_date'] = (new Jalalian(
                (int) $fields['due_date']['year'],
                (int) $fields['due_date']['month'],
                (int) $fields['due_date']['day']
            ))->toCarbon()->format('Y-m-d');
        }

        $cheque->update($update);

        return response($cheque->fresh(), 200);
    }

    public function destroy(Request $request, Cheque $cheque)
    {
        $this->assertModelBelongsToStaffAtelier($request, $cheque);

        if ($cheque->status === Cheque::STATUS_CLEARED) {
            return response()->json([
                'message' => 'چک وصول‌شده قابل حذف نیست.',
            ], 422);
        }

        if ($cheque->purchase_id) {
            return response()->json([
                'message' => 'چکی که به فروش وصل شده قابل حذف نیست.',
            ], 422);
        }

        $cheque->delete();

        return response(['message' => 'چک با موفقیت حذف شد'], 200);
    }
}
