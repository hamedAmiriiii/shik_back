# نقشه راه سامانه مسابقات بیلیارد (MVP)

دامین: `billiard.webinoplus.ir` (پوشش جدا روی **همان** `shikshoohub`)  
فرانت: `shikshoohub/src/app/billiard/` — مثل الگوی repair/omidtamir  
API: `/api/billiard/*`  
جداول: فقط `billiard_*`  
SQL دستی: `database/sql/create_billiard_tables_manual.sql`

وضعیت‌ها: `شروع‌نشده` | `در حال انجام` | `تمام`

| مرحله | عنوان | وضعیت |
|------|--------|--------|
| ۱ | اسکلت + جداول + OTP + ایزوله توکن | تمام |
| ۲ | CRUD ادمین (رشته/بازیکن/تورنمنت/میز/اخبار) | تمام |
| ۳ | صفحات عمومی | تمام |
| ۴ | ثبت‌نام + پرداخت | تمام |
| ۵ | قرعه + Single Elim + نتیجه | تمام |
| ۶ | رنکینگ اسنپ‌شات | تمام |
| ۷ | دیپلوی دامین | تمام |

## env لازم

```
BILLIARD_ADMIN_PHONES=09xxxxxxxxx
BILLIARD_FRONTEND_URL=https://billiard.webinoplus.ir
BILLIARD_BRAND_NAME=هیأت بیلیارد
```

## migrate

```
php artisan migrate
```

یا SQL دستی در صورت نیاز از migration هم‌نام.
