<?php

namespace App\Services\Moadian;

/** اتصال از طریق شرکت معتمد؛ تا انتخاب شرکت، فقط خطای پیکربندی برمی‌گرداند. */
class MoadianTspGateway implements MoadianGateway
{
    private const MESSAGE = 'اتصال از طریق شرکت معتمد هنوز پیاده‌سازی نشده است؛ در تنظیمات روش «اتصال مستقیم» را انتخاب کنید.';

    public function fiscalInformation(): array
    {
        throw new MoadianConfigException(self::MESSAGE);
    }

    public function sendInvoices(array $invoices, array $documentIds = []): array
    {
        throw new MoadianConfigException(self::MESSAGE);
    }

    public function inquiryByReferenceNumbers(array $referenceNumbers, array $documentIds = []): array
    {
        throw new MoadianConfigException(self::MESSAGE);
    }
}
