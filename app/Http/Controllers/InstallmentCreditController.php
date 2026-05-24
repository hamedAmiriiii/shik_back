<?php

namespace App\Http\Controllers;

use App\Models\UserCreditGrant;
use App\Models\UserShiksho;
use App\Services\UserCreditGrantService;
use App\Tools\PriceTools;
use App\Tools\SmsTools;
use Illuminate\Http\Request;

class InstallmentCreditController extends Controller
{
    /**
     * لیست اعتبارات اقساطی کاربران (همان فروشگاه)
     */
    public function index(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = UserShiksho::where('atelier_id', $atelierId);

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->phone)) {
                        $q->where('phone', 'like', '%'.$searchDataModel->phone.'%');
                    }
                } elseif (is_string($searchDataModel)) {
                    $q->where('phone', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        if ($request->has('min_credit')) {
            $query->where('installment_credit', '>=', $request->input('min_credit'));
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response($users, 200);
    }

    /**
     * نمایش اعتبار یک کاربر خاص
     */
    public function show(Request $request, $phone)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $user = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'کاربر یافت نشد',
            ], 404);
        }

        return response($user, 200);
    }

    /**
     * افزودن یا به‌روزرسانی اعتبار اقساطی و اعتبار عادی کاربر
     */
    public function store(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'phone' => 'required|string|digits:11',
            'installment_credit' => 'required|numeric|min:0',
            'credit' => 'required|numeric|min:0',
        ]);

        $phone = $request->input('phone');
        $installmentCredit = PriceTools::roundToThousand((float) $request->input('installment_credit'));
        $regularCredit = PriceTools::roundToThousand((float) $request->input('credit'));

        $user = UserShiksho::firstOrCreate(
            ['phone' => $phone, 'atelier_id' => $atelierId],
            ['credit' => 0, 'installment_credit' => 0, 'credit_last_updated_at' => now()]
        );

        $oldInstallmentCredit = $user->installment_credit;
        $oldRegularCredit = $user->credit;
        $user->installment_credit = $installmentCredit;
        $user->credit = $regularCredit;
        $user->credit_last_updated_at = now();
        $user->save();

        UserCreditGrantService::recordManualChange(
            $atelierId,
            $phone,
            UserCreditGrant::TYPE_REGULAR,
            (float) $oldRegularCredit,
            $regularCredit
        );
        UserCreditGrantService::recordManualChange(
            $atelierId,
            $phone,
            UserCreditGrant::TYPE_INSTALLMENT,
            (float) $oldInstallmentCredit,
            $installmentCredit
        );

        $installmentFormatted = number_format($installmentCredit, 0);
        $creditFormatted = number_format($regularCredit, 0);
        $shopName = SmsTools::shopSmsBrand($atelierId);
        $text = "{$shopName}\nاعتبار خرید اقساطی شما تا {$installmentFormatted} تومان و اعتبار عادی تا {$creditFormatted} تومان شارژ شد";
        try {
            SmsTools::sendShopSms($phone, $text, null, $installmentCredit, 'installment_credit', $atelierId);
        } catch (\App\Exceptions\InsufficientShopSmsQuotaException $e) {
            return $e->toResponse($request);
        }

        return response([
            'message' => 'اعتبار اقساطی و اعتبار عادی با موفقیت ثبت شد',
            'user' => $user,
            'old_installment_credit' => $oldInstallmentCredit,
            'new_installment_credit' => $installmentCredit,
            'old_regular_credit' => $oldRegularCredit,
            'new_regular_credit' => $regularCredit,
        ], 201);
    }

    /**
     * به‌روزرسانی اعتبار اقساطی و اعتبار عادی کاربر
     */
    public function update(Request $request, $phone)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'installment_credit' => 'nullable|numeric|min:0',
            'credit' => 'nullable|numeric|min:0',
        ]);

        $user = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'کاربر یافت نشد',
            ], 404);
        }

        $oldInstallmentCredit = $user->installment_credit;
        $oldRegularCredit = $user->credit;

        if ($request->has('installment_credit')) {
            $newInstallment = PriceTools::roundToThousand((float) $request->input('installment_credit'));
            UserCreditGrantService::recordManualChange(
                $atelierId,
                $phone,
                UserCreditGrant::TYPE_INSTALLMENT,
                (float) $oldInstallmentCredit,
                $newInstallment
            );
            $user->installment_credit = $newInstallment;
        }
        if ($request->has('credit')) {
            $newRegular = PriceTools::roundToThousand((float) $request->input('credit'));
            UserCreditGrantService::recordManualChange(
                $atelierId,
                $phone,
                UserCreditGrant::TYPE_REGULAR,
                (float) $oldRegularCredit,
                $newRegular
            );
            $user->credit = $newRegular;
            $user->credit_last_updated_at = now();
        }
        $user->save();

        $newInstallmentCredit = $user->installment_credit;
        $newRegularCredit = $user->credit;

        $installmentFormatted = number_format($newInstallmentCredit, 0);
        $creditFormatted = number_format($newRegularCredit, 0);
        $shopName = SmsTools::shopSmsBrand($atelierId);
        $text = "{$shopName}\nاعتبار خرید اقساطی شما {$installmentFormatted} تومان و اعتبار عادی {$creditFormatted} تومان ثبت شد";
        try {
            SmsTools::sendShopSms($phone, $text, null, $newInstallmentCredit, 'installment_credit', $atelierId);
        } catch (\App\Exceptions\InsufficientShopSmsQuotaException $e) {
            return $e->toResponse($request);
        }

        return response([
            'message' => 'اعتبارات با موفقیت به‌روزرسانی شد',
            'user' => $user,
            'old_installment_credit' => $oldInstallmentCredit,
            'new_installment_credit' => $newInstallmentCredit,
            'old_regular_credit' => $oldRegularCredit,
            'new_regular_credit' => $newRegularCredit,
        ], 200);
    }

    /**
     * حذف اعتبار اقساطی و اعتبار عادی کاربر (صفر کردن)
     */
    public function destroy(Request $request, $phone)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $user = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'کاربر یافت نشد',
            ], 404);
        }

        $oldInstallmentCredit = $user->installment_credit;
        $oldRegularCredit = $user->credit;
        UserCreditGrantService::recordManualChange(
            $atelierId,
            $phone,
            UserCreditGrant::TYPE_REGULAR,
            (float) $oldRegularCredit,
            0.0
        );
        UserCreditGrantService::recordManualChange(
            $atelierId,
            $phone,
            UserCreditGrant::TYPE_INSTALLMENT,
            (float) $oldInstallmentCredit,
            0.0
        );
        $user->installment_credit = 0;
        $user->credit = 0;
        $user->credit_last_updated_at = now();
        $user->save();

        return response([
            'message' => 'تمام اعتبارات با موفقیت حذف شد',
            'user' => $user,
            'old_installment_credit' => $oldInstallmentCredit,
            'old_regular_credit' => $oldRegularCredit,
        ], 200);
    }
}
