<?php

namespace App\Http\Concerns;

use App\Models\ShopAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * حساب پرداخت (حساب اصلی فروشگاه یا تنخواه) برای هزینه‌ها و فاکتورها.
 */
trait ResolvesPaymentAccount
{
    protected function supportsPaymentAccount(string $table): bool
    {
        return Schema::hasTable('shop_accounts') && Schema::hasColumn($table, 'shop_account_id');
    }

    /**
     * @return array<string, string>
     */
    protected function paymentAccountRules(string $table, bool $required = false): array
    {
        if (! $this->supportsPaymentAccount($table)) {
            return [];
        }

        return [
            'shop_account_id' => ($required ? 'required' : 'nullable').'|integer|exists:shop_accounts,id',
        ];
    }

    /**
     * حساب انتخاب‌شده را اعتبارسنجی می‌کند. در صورت خطا پیام برمی‌گرداند.
     */
    protected function paymentAccountError(int $atelierId, $shopAccountId): ?string
    {
        if ($shopAccountId === null || $shopAccountId === '') {
            return null;
        }

        $account = ShopAccount::find((int) $shopAccountId);
        if (! $account || (int) $account->atelier_id !== $atelierId) {
            return 'حساب انتخاب‌شده متعلق به این فروشگاه نیست.';
        }
        if (! $account->is_active) {
            return 'حساب انتخاب‌شده غیرفعال است.';
        }

        return null;
    }

    /**
     * افزودن خلاصهٔ حساب برداشت به رکورد برای پاسخ API.
     *
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    protected function attachPaymentAccount(Model $model)
    {
        if (! $this->supportsPaymentAccount($model->getTable())) {
            return $model;
        }

        /** @var ShopAccount|null $account */
        $account = $model->shopAccount()->first();
        $model->setRelation('shopAccount', $account);
        $model->setAttribute('payment_account', $account ? [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type ?: ShopAccount::TYPE_SHOP,
            'type_label' => $account->typeLabel(),
        ] : null);

        return $model;
    }
}
