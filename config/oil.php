<?php

return [
    /*
     * میانگین کارکرد سالانه خودرو در ایران برای تخمین نوبت تعویض روغن.
     * ۲۵ تا ۳۰ هزار کیلومتر → وسط: ۲۷۵۰۰ ≈ ۷۵ کیلومتر در روز.
     */
    'km_per_year' => (int) env('OIL_KM_PER_YEAR', 27500),

    /** چند روز زودتر از رسیدن به کیلومتر بعدی پیامک یادآوری برود */
    'reminder_lookahead_days' => (int) env('OIL_REMINDER_LOOKAHEAD_DAYS', 10),

    /**
     * برای صدا زدن بدون لاگین (مثلاً cron-job.org):
     * GET/POST /api/oil/reminders/run?token=...
     */
    'reminder_token' => env('OIL_REMINDER_TOKEN'),
];
