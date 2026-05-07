# API ثبت آدرس‌های کاربر و استفاده در سبد خرید

## مقدمه
سیستم جدید برای ذخیره‌سازی آدرس‌های کاربر و استفاده از آن‌ها در هنگام خرید طراحی شده است.

---

## 1. API برای ثبت و مدیریت آدرس‌های کاربر

### الف) دریافت لیست تمام آدرس‌های کاربر
**درخواست:**
```
GET /api/customer-addresses
Authorization: Bearer {token}
```

**پاسخ (موفق):**
```json
{
    "message": "لیست آدرس‌های مشتری",
    "addresses": [
        {
            "id": 1,
            "customer_id": 5,
            "title": "خانه",
            "name": "احمد",
            "last_name": "علی‌پور",
            "phone": "09121234567",
            "address": "خیابان نیاوران، پلاک 123",
            "state_id": 1,
            "state_name": "تهران",
            "city_id": 10,
            "city_name": "تهران",
            "postal_code": "1234567890",
            "is_default": true,
            "created_at": "2026-04-29T10:30:00Z",
            "updated_at": "2026-04-29T10:30:00Z"
        }
    ],
    "count": 1
}
```

---

### ب) ثبت آدرس جدید
**درخواست:**
```
POST /api/customer-addresses
Authorization: Bearer {token}
Content-Type: application/json

{
    "title": "خانه",
    "name": "احمد",
    "last_name": "علی‌پور",
    "phone": "09121234567",
    "address": "خیابان نیاوران، پلاک 123",
    "state_id": 1,
    "city_id": 10,
    "postal_code": "1234567890",
    "is_default": false
}
```

**پاسخ (موفق - 201):**
```json
{
    "message": "آدرس با موفقیت ذخیره شد",
    "address": {
        "customer_id": 5,
        "title": "خانه",
        "name": "احمد",
        "last_name": "علی‌پور",
        "phone": "09121234567",
        "address": "خیابان نیاوران، پلاک 123",
        "state_id": 1,
        "state_name": "تهران",
        "city_id": 10,
        "city_name": "تهران",
        "postal_code": "1234567890",
        "is_default": true,
        "id": 1,
        "created_at": "2026-04-29T10:30:00Z",
        "updated_at": "2026-04-29T10:30:00Z"
    }
}
```

**نکات:**
- اگر این اولین آدرسی باشد که کاربر ثبت می‌کند، به طور خودکار به عنوان پیش‌فرض تعیین می‌شود.
- اگر `is_default` را `true` قرار دهید، آدرس‌های دیگر به طور خودکار `is_default=false` می‌شوند.

---

### ج) دریافت جزئیات یک آدرس
**درخواست:**
```
GET /api/customer-addresses/{address_id}
Authorization: Bearer {token}
```

**پاسخ (موفق):**
```json
{
    "message": "جزئیات آدرس",
    "address": {
        "id": 1,
        "customer_id": 5,
        "title": "خانه",
        "name": "احمد",
        "last_name": "علی‌پور",
        "phone": "09121234567",
        "address": "خیابان نیاوران، پلاک 123",
        "state_id": 1,
        "state_name": "تهران",
        "city_id": 10,
        "city_name": "تهران",
        "postal_code": "1234567890",
        "is_default": true,
        "created_at": "2026-04-29T10:30:00Z",
        "updated_at": "2026-04-29T10:30:00Z"
    }
}
```

---

### د) ویرایش آدرس
**درخواست:**
```
PUT /api/customer-addresses/{address_id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "title": "محل کار",
    "name": "علی",
    "phone": "09121234568",
    "address": "خیابان نیاوران، پلاک 456"
}
```

**پاسخ (موفق):**
```json
{
    "message": "آدرس با موفقیت بروزرسانی شد",
    "address": {
        "id": 1,
        "customer_id": 5,
        "title": "محل کار",
        "name": "علی",
        "last_name": "علی‌پور",
        "phone": "09121234568",
        "address": "خیابان نیاوران، پلاک 456",
        "state_id": 1,
        "state_name": "تهران",
        "city_id": 10,
        "city_name": "تهران",
        "postal_code": "1234567890",
        "is_default": true,
        "created_at": "2026-04-29T10:30:00Z",
        "updated_at": "2026-04-29T10:30:00Z"
    }
}
```

---

### هـ) حذف آدرس
**درخواست:**
```
DELETE /api/customer-addresses/{address_id}
Authorization: Bearer {token}
```

**پاسخ (موفق):**
```json
{
    "message": "آدرس با موفقیت حذف شد"
}
```

**خطا (اگر آدرس در سبد خریدی استفاده شود):**
```json
{
    "error": "این آدرس در سبد خریدی استفاده می‌شود. ابتدا سبد را تغییر دهید"
}
```

---

### و) تعیین آدرس به عنوان پیش‌فرض
**درخواست:**
```
POST /api/customer-addresses/{address_id}/set-default
Authorization: Bearer {token}
```

**پاسخ (موفق):**
```json
{
    "message": "آدرس به عنوان پیش‌فرض تعیین شد",
    "address": {
        "id": 1,
        "customer_id": 5,
        "title": "خانه",
        "name": "احمد",
        "last_name": "علی‌پور",
        "phone": "09121234567",
        "address": "خیابان نیاوران، پلاک 123",
        "state_id": 1,
        "state_name": "تهران",
        "city_id": 10,
        "city_name": "تهران",
        "postal_code": "1234567890",
        "is_default": true,
        "created_at": "2026-04-29T10:30:00Z",
        "updated_at": "2026-04-29T10:30:00Z"
    }
}
```

---

## 2. API برای استفاده از آدرس در سبد خرید

### الف) انتخاب آدرس برای سبد خرید
**درخواست:**
```
POST /api/cart/set-address
Authorization: Bearer {token}
Content-Type: application/json

{
    "address_id": 1
}
```

**پاسخ (موفق):**
```json
{
    "message": "آدرس برای سبد خرید تعیین شد",
    "cart": {
        "id": 1,
        "customer_id": 5,
        "address_id": 1,
        "status": "pending",
        "shipping_name": null,
        "shipping_last_name": null,
        "shipping_phone": null,
        "shipping_address": null,
        "shipping_state_id": null,
        "shipping_state_name": null,
        "shipping_city_id": null,
        "shipping_city_name": null,
        "shipping_postal_code": null,
        "address": {
            "id": 1,
            "customer_id": 5,
            "title": "خانه",
            "name": "احمد",
            "last_name": "علی‌پور",
            "phone": "09121234567",
            "address": "خیابان نیاوران، پلاک 123",
            "state_id": 1,
            "state_name": "تهران",
            "city_id": 10,
            "city_name": "تهران",
            "postal_code": "1234567890",
            "is_default": true,
            "created_at": "2026-04-29T10:30:00Z",
            "updated_at": "2026-04-29T10:30:00Z"
        },
        "created_at": "2026-04-29T10:30:00Z",
        "updated_at": "2026-04-29T10:30:00Z"
    },
    "items": [],
    "total": 0,
    "items_count": 0
}
```

---

### ب) نمایش سبد خرید با آدرس
**درخواست:**
```
GET /api/cart
Authorization: Bearer {token}
```

**پاسخ (موفق):**
```json
{
    "cart": {
        "id": 1,
        "customer_id": 5,
        "address_id": 1,
        "status": "pending",
        "address": {
            "id": 1,
            "customer_id": 5,
            "name": "احمد",
            "last_name": "علی‌پور",
            "phone": "09121234567",
            "address": "خیابان نیاوران، پلاک 123",
            "state_id": 1,
            "state_name": "تهران",
            "city_id": 10,
            "city_name": "تهران",
            "postal_code": "1234567890",
            "is_default": true
        },
        "created_at": "2026-04-29T10:30:00Z",
        "updated_at": "2026-04-29T10:30:00Z"
    },
    "items": [
        {
            "id": 1,
            "cart_id": 1,
            "product_id": 5,
            "quantity": 2,
            "price": 50000,
            "size": "M",
            "color": "red",
            "created_at": "2026-04-29T10:30:00Z",
            "updated_at": "2026-04-29T10:30:00Z",
            "product": {
                "id": 5,
                "name": "تی شرت",
                "sale_price": 50000
            }
        }
    ],
    "total": 100000,
    "items_count": 2
}
```

---

### ج) تکمیل سفارش
**درخواست:**
```
POST /api/cart/complete-order
Authorization: Bearer {token}
Content-Type: application/json

{
    "use_credit": false
}
```

**پاسخ (موفق):**
```json
{
    "message": "سفارش با موفقیت ثبت شد",
    "purchase": {
        "id": 1,
        "cart_id": 1,
        "phone": "09121234567",
        "total_amount": 100000,
        "credit_used": 0,
        "credit_earned": 1000,
        "created_at": "2026-04-29T10:30:00Z",
        "updated_at": "2026-04-29T10:30:00Z"
    },
    "cart": {
        "id": 1,
        "customer_id": 5,
        "address_id": 1,
        "status": "completed",
        "address": {
            "id": 1,
            "name": "احمد",
            "phone": "09121234567",
            "address": "خیابان نیاوران، پلاک 123"
        }
    }
}
```

---

## 3. خطاهای عام

### عدم احراز هویت
```json
{
    "message": "Unauthenticated"
}
```

### آدرس یافت نشد
```json
{
    "error": "آدرس یافت نشد یا شما دسترسی ندارید"
}
```

### سبد خرید یافت نشد
```json
{
    "error": "سبد خرید یافت نشد"
}
```

### آدرس هنوز انتخاب نشده
```json
{
    "error": "اطلاعات ارسال کامل نیست. لطفاً ابتدا آدرس را انتخاب کنید یا اطلاعات ارسال را تکمیل کنید."
}
```

---

## 4. جریان کار پیشنهادی

### مرحله 1: ثبت آدرس‌های کاربر
```bash
POST /api/customer-addresses
```

### مرحله 2: دریافت لیست آدرس‌ها
```bash
GET /api/customer-addresses
```

### مرحله 3: افزودن محصولات به سبد
```bash
POST /api/cart
{
    "products": [
        {
            "product_id": 1,
            "quantity": 2
        }
    ]
}
```

### مرحله 4: انتخاب آدرس برای سبد
```bash
POST /api/cart/set-address
{
    "address_id": 1
}
```

### مرحله 5: تکمیل سفارش
```bash
POST /api/cart/complete-order
{
    "use_credit": false
}
```

---

## 5. جدول `customer_addresses`

| ستون | نوع | توضیح |
|------|------|--------|
| id | BIGINT | شناسه یکتا |
| customer_id | BIGINT | شناسه مشتری |
| title | STRING | عنوان آدرس (مثلاً خانه، محل کار) |
| name | STRING | نام |
| last_name | STRING | نام خانوادگی |
| phone | STRING | شماره تلفن (11 رقمی) |
| address | TEXT | متن آدرس |
| state_id | BIGINT | شناسه استان |
| state_name | STRING | نام استان |
| city_id | BIGINT | شناسه شهر |
| city_name | STRING | نام شهر |
| postal_code | STRING | کد پستی |
| is_default | BOOLEAN | آیا آدرس پیش‌فرض است |
| created_at | TIMESTAMP | تاریخ ایجاد |
| updated_at | TIMESTAMP | تاریخ آخرین بروزرسانی |

---

## 6. تغييرات در جدول `carts`

افزودن ستون `address_id` برای لینک به آدرس‌های ذخیره‌ شده:

| ستون | نوع | توضیح |
|------|------|--------|
| address_id | BIGINT (NULLABLE) | شناسه آدرس انتخاب‌ شده |

---

## 7. نکات مهم

1. **شماره تلفن**: هنگام تکمیل سفارش، شماره تلفن از آدرس انتخاب‌شده گرفته می‌شود.
2. **آدرس پیش‌فرض**: اولین آدرسی که کاربر ثبت می‌کند به طور خودکار به عنوان پیش‌فرض تعیین می‌شود.
3. **محدودیت حذف**: آدرسی که در سبد فعلی استفاده شود حذف نمی‌شود.
4. **سازگاری**: سیستم هنوز از روش قدیمی (shipping_* fields) پشتیبانی می‌کند.

---

## 8. کوئری‌های SQL دستی

اگر نمی‌خواهید میگریشن Laravel را اجرا کنید، می‌توانید این کوئری‌های SQL را مستقیم اجرا کنید:

### ایجاد جدول customer_addresses:
```sql
CREATE TABLE `customer_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `address` LONGTEXT NOT NULL,
  `title` VARCHAR(255) NULL,
  `state_id` INT(4) NOT NULL,
  `state_name` VARCHAR(255) NULL,
  `city_id` INT(20) NOT NULL,
  `city_name` VARCHAR(255) NULL,
  `postal_code` VARCHAR(10) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `customer_addresses_customer_id_foreign` 
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_addresses_state_id_foreign` 
    FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_addresses_city_id_foreign` 
    FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE CASCADE,
  INDEX `customer_addresses_customer_id_index` (`customer_id`),
  INDEX `customer_addresses_is_default_index` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### افزودن ستون address_id به جدول carts:
```sql
ALTER TABLE `carts` 
ADD COLUMN `address_id` BIGINT UNSIGNED NULL AFTER `customer_id`;

ALTER TABLE `carts` 
ADD CONSTRAINT `carts_address_id_foreign` 
  FOREIGN KEY (`address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL;

ALTER TABLE `carts` 
ADD INDEX `carts_address_id_index` (`address_id`);
```

**⚠️ نکات:**
- تمام کوئری‌ها را به ترتیب اجرا کنید
- اگر جدول یا ستون قبلاً وجود داشته باشد، خطا می‌دهد
- برای اجرای محفوظ، از `IF NOT EXISTS` استفاده کنید (نسخه پیشرفته)
