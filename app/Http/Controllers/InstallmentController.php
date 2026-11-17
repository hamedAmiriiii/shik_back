<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use App\Models\InstallmentPayment;
use App\Models\Purchase;
use App\Models\UserShiksho;
use App\Tools\PriceTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallmentController extends Controller
{
    /**
     * لیست قسط‌های یک خرید
     */
    public function index(Request $request, Purchase $purchase)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $purchase);

        $installments = $purchase->installments()->orderBy('installment_number')->get();

        return response([
            'purchase' => $purchase,
            'installments' => $installments,
            'paid_amount' => $purchase->paid_amount,
            'remaining_amount' => $purchase->remaining_amount,
        ], 200);
    }

    /**
     * پرداخت یک قسط (کامل یا جزئی).
     * اگر مبلغ بیشتر از قسط فعلی باشد، قسط‌های بعدی به‌ترتیب تسویه یا از مبلغشان کسر می‌شود.
     */
    public function pay(Request $request, Purchase $purchase, Installment $installment)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $purchase);

        if ($installment->purchase_id !== $purchase->id) {
            return response()->json(['message' => 'این قسط متعلق به این خرید نیست'], 400);
        }

        if ($installment->is_paid) {
            return response()->json(['message' => 'این قسط قبلاً پرداخت شده است'], 400);
        }

        $fields = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $notes = $fields['notes'] ?? null;
        $requested = isset($fields['amount']) ? PriceTools::roundToman((float) $fields['amount']) : 0.0;

        DB::beginTransaction();
        try {
            $locked = Installment::query()
                ->where('id', $installment->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->is_paid) {
                DB::rollBack();

                return response()->json(['message' => 'این قسط قبلاً پرداخت شده است'], 400);
            }

            $targets = Installment::query()
                ->where('purchase_id', $purchase->id)
                ->where('is_paid', false)
                ->where('installment_number', '>=', $locked->installment_number)
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();

            $remainingPayable = PriceTools::roundToman((float) $targets->sum('amount'));
            if ($remainingPayable < 1) {
                DB::rollBack();

                return response()->json(['message' => 'مبلغ قابل پرداخت این قسط‌ها صفر است'], 422);
            }

            $payAmount = $requested > 0 ? $requested : PriceTools::roundToman((float) $locked->amount);
            if ($payAmount < 1) {
                DB::rollBack();

                return response()->json(['message' => 'مبلغ پرداخت باید حداقل ۱ تومان باشد'], 422);
            }

            if (($payAmount - $remainingPayable) > 0.02) {
                DB::rollBack();

                return response()->json([
                    'message' => 'مبلغ پرداخت از مجموع قسط‌های باقی‌مانده بیشتر است',
                    'payable_amount' => $remainingPayable,
                ], 422);
            }

            $left = $payAmount;
            $primary = null;
            $affected = [];

            foreach ($targets as $row) {
                if ($left < 0.01) {
                    break;
                }

                $rowAmount = PriceTools::roundToman((float) $row->amount);
                if ($rowAmount < 1) {
                    continue;
                }

                if (($left + 0.02) >= $rowAmount) {
                    $row->update([
                        'is_paid' => true,
                        'paid_at' => now(),
                        'notes' => $notes ?: $row->notes,
                    ]);
                    $left = round($left - $rowAmount, 2);
                    $fresh = $row->fresh();
                    $affected[] = $fresh;
                    if ($primary === null) {
                        $primary = $fresh;
                    }
                } else {
                    $newAmount = PriceTools::roundToman($rowAmount - $left);
                    if ($newAmount < 1) {
                        $row->update([
                            'is_paid' => true,
                            'paid_at' => now(),
                            'notes' => $notes ?: $row->notes,
                        ]);
                    } else {
                        $partialNote = 'کسر '.number_format($left).' تومان بابت پرداخت جزئی';
                        $row->update([
                            'amount' => $newAmount,
                            'notes' => trim(($notes ? $notes.' | ' : '').$partialNote),
                        ]);
                    }
                    $left = 0;
                    $fresh = $row->fresh();
                    $affected[] = $fresh;
                    if ($primary === null) {
                        $primary = $fresh;
                    }
                }
            }

            if ($primary === null) {
                DB::rollBack();

                return response()->json(['message' => 'خطا در اعمال پرداخت قسط'], 500);
            }

            $payment = null;
            if (Schema::hasTable('installment_payments')) {
                $payment = InstallmentPayment::create([
                    'purchase_id' => $purchase->id,
                    'installment_id' => $locked->id,
                    'amount' => $payAmount,
                    'notes' => $notes,
                    'paid_at' => now(),
                ]);
            }

            \App\Services\AccountingSalePoster::postInstallmentPay($primary, $payAmount, $payment);

            if ($purchase->phone) {
                $atelierId = $purchase->atelier_id;
                $userQuery = UserShiksho::where('phone', $purchase->phone);
                if ($atelierId) {
                    $userQuery->where('atelier_id', $atelierId);
                }
                $userShiksho = $userQuery->first();
                if ($userShiksho) {
                    $userShiksho->addInstallmentCredit($payAmount);
                }
            }

            DB::commit();

            $purchase->refresh()->load('installments');
            $primary->load('purchase');

            $message = count($affected) > 1 || ((float) ($affected[0]->amount ?? 0) !== (float) $locked->amount && ! $affected[0]->is_paid)
                ? 'پرداخت ثبت شد. اقساط به‌روزرسانی شدند.'
                : 'قسط با موفقیت پرداخت شد';

            return response([
                'message' => $message,
                'paid_amount' => $payAmount,
                'installment' => $primary,
                'affected_installments' => $affected,
                'purchase' => $purchase,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'خطا در پرداخت قسط',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * لیست قسط‌های یک مشتری بر اساس شماره تلفن
     */
    public function getByPhone(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');

        $purchases = Purchase::forAtelier($atelierId)
            ->where('phone', $phone)
            ->where('payment_type', 'installment')
            ->with(['installments' => function ($query) {
                $query->orderBy('installment_number');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response([
            'phone' => $phone,
            'atelier_id' => $atelierId,
            'purchases' => $purchases,
        ], 200);
    }

    /**
     * لیست قسط‌های پرداخت نشده (همان فروشگاه)
     */
    public function unpaid(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = Installment::where('is_paid', false)
            ->whereHas('purchase', function ($q) use ($atelierId) {
                $q->forAtelier($atelierId);
            })
            ->with(['purchase' => function ($q) {
                $q->select('id', 'phone', 'total_amount', 'payment_type', 'atelier_id', 'created_at');
            }])
            ->orderBy('due_date');

        if ($request->boolean('overdue')) {
            $query->where('due_date', '<', now()->toDateString());
        }

        if ($request->boolean('due_soon')) {
            $threeDaysLater = now()->addDays(3)->toDateString();
            $query->whereBetween('due_date', [now()->toDateString(), $threeDaysLater]);
        }

        $installments = $query->paginate($request->input('per_page', 20));

        return response($installments, 200);
    }
}
