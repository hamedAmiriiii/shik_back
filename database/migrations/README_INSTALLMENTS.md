# راهنمای اجرای دستی Migration برای سیستم اقساطی

این فایل‌ها برای اجرای دستی تغییرات دیتابیس برای سیستم اقساطی استفاده می‌شوند.

## فایل‌های موجود

1. **`create_installments_manual.sql`** - اجرای migration (ایجاد جداول و ستون‌ها)
2. **`rollback_installments_manual.sql`** - برگرداندن تغییرات (rollback)

## مراحل اجرا

### مرحله 1: پشتیبان‌گیری از دیتابیس
```bash
# قبل از هر کاری، از دیتابیس بکاپ بگیرید
mysqldump -u username -p database_name > backup_before_installments.sql
```

### مرحله 2: اجرای Migration

#### روش 1: استفاده از MySQL Command Line
```bash
mysql -u username -p database_name < database/migrations/create_installments_manual.sql
```

#### روش 2: استفاده از phpMyAdmin یا MySQL Workbench
1. فایل `create_installments_manual.sql` را باز کنید
2. محتوای آن را کپی کنید
3. در phpMyAdmin یا MySQL Workbench، SQL Query را باز کنید
4. محتوا را paste کنید و اجرا کنید

#### روش 3: استفاده از Laravel Tinker
```php
DB::unprepared(file_get_contents('database/migrations/create_installments_manual.sql'));
```

### مرحله 3: بررسی نتایج

بعد از اجرا، بررسی کنید که:

1. جدول `installments` ایجاد شده باشد:
```sql
SHOW TABLES LIKE 'installments';
DESCRIBE installments;
```

2. ستون‌های جدید در `purchases` اضافه شده باشند:
```sql
DESCRIBE purchases;
```

3. Foreign key ها درست ایجاد شده باشند:
```sql
SHOW CREATE TABLE installments;
```

## در صورت نیاز به Rollback

اگر نیاز به برگرداندن تغییرات دارید:

```bash
mysql -u username -p database_name < database/migrations/rollback_installments_manual.sql
```

**توجه:** این کار تمام داده‌های قسط‌ها را حذف می‌کند. قبل از اجرا مطمئن شوید.

## مشکلات احتمالی و راه حل

### خطا: Foreign key constraint fails
- مطمئن شوید که جدول `purchases` وجود دارد
- بررسی کنید که تمام `purchase_id` های موجود در جداول دیگر، در جدول `purchases` وجود دارند

### خطا: Column already exists
- این یعنی migration قبلاً اجرا شده است
- می‌توانید از `rollback_installments_manual.sql` استفاده کنید و دوباره اجرا کنید

### خطا: Table already exists
- این یعنی جدول `installments` قبلاً ایجاد شده است
- می‌توانید از `DROP TABLE IF EXISTS installments;` استفاده کنید و دوباره اجرا کنید

## بررسی صحت اجرا

بعد از اجرای موفق، باید:
- ✅ جدول `installments` وجود داشته باشد
- ✅ ستون‌های `payment_type`, `installment_count`, `installment_amount` در `purchases` اضافه شده باشند
- ✅ Foreign key بین `installments` و `purchases` برقرار باشد
- ✅ Index ها ایجاد شده باشند

## ساختار جدول installments

جدول `installments` شامل فیلدهای زیر است:
- `id`: شناسه یکتا
- `purchase_id`: شناسه خرید (Foreign Key)
- `installment_number`: شماره قسط (1, 2, 3, ...)
- `amount`: مبلغ قسط
- `due_date`: تاریخ سررسید
- `is_paid`: وضعیت پرداخت (0 یا 1)
- `paid_at`: تاریخ پرداخت (nullable)
- `notes`: یادداشت‌ها (nullable)
- `created_at`, `updated_at`: زمان‌های ایجاد و به‌روزرسانی

## ساختار فیلدهای جدید در purchases

جدول `purchases` شامل فیلدهای جدید زیر است:
- `payment_type`: نوع پرداخت (`cash` یا `installment`) - پیش‌فرض: `cash`
- `installment_count`: تعداد اقساط (nullable)
- `installment_amount`: مبلغ هر قسط (nullable)

## نکات مهم

1. **همیشه قبل از اجرا بکاپ بگیرید**
2. **در محیط production، ابتدا در محیط test اجرا کنید**
3. **اگر migration قبلاً اجرا شده، نیازی به اجرای مجدد نیست**
4. **بعد از اجرا، کد Laravel را deploy کنید تا از سیستم اقساطی استفاده کند**

