<?php

return [
    /*
     * میانگین کارکرد همهٔ ماشین‌ها: ۲۷۰۰۰ کیلومتر در سال ≈ ۷۴ کیلومتر در روز.
     * روز تا نوبت = (next_km − km) ÷ (۲۷۰۰۰ / ۳۶۵)
     */
    'km_per_year' => (int) env('OIL_KM_PER_YEAR', 27000),

    /** چند روز زودتر از رسیدن به کیلومتر بعدی پیامک یادآوری برود */
    'reminder_lookahead_days' => (int) env('OIL_REMINDER_LOOKAHEAD_DAYS', 10),

    /** اگر اجرای API دیر شد، تا چند روز بعد از نوبت هنوز یک‌بار پیامک برود */
    'reminder_max_overdue_days' => (int) env('OIL_REMINDER_MAX_OVERDUE_DAYS', 90),

    /**
     * برای صدا زدن بدون لاگین (مثلاً cron-job.org):
     * GET/POST /api/oil/reminders/run?token=...
     */
    'reminder_token' => env('OIL_REMINDER_TOKEN'),

    /**
     * لینک سابقهٔ مشتری بدون لاگین.
     * پیامک لینک فعلاً خاموش است (OIL_SEND_HISTORY_LINK_SMS=false).
     */
    'public_base_url' => rtrim((string) env('OIL_PUBLIC_BASE_URL', 'https://webinoo-plus.ir'), '/'),

    'send_history_link_sms' => filter_var(env('OIL_SEND_HISTORY_LINK_SMS', false), FILTER_VALIDATE_BOOLEAN),
];
