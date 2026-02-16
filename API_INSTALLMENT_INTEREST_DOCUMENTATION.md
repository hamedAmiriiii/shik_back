# مستندات API های سود ماهانه اقساط

## 1. دریافت اعتبار و سقف خرید اقساطی کاربر

### 1.1 دریافت اعتبار و سقف خرید اقساطی

**Endpoint:** `GET /api/purchased-products/installment-credit?phone=09120000000`

**Authentication:** نیاز به احراز هویت ندارد (Public)

**Query Parameters:**
- `phone`: الزامی، شماره تلفن 11 رقمی

**Response:**
```json
{
  "phone": "09120000000",
  "credit": 500000,
  "installment_limit": 500000,
  "can_buy_installment": true
}
```

**مثال استفاده:**
```bash
curl "http://api.example.com/api/purchased-products/installment-credit?phone=09120000000"
```

**توضیحات:**
- `credit`: اعتبار فعلی کاربر
- `installment_limit`: سقف خرید اقساطی (همان اعتبار کاربر)
- `can_buy_installment`: آیا کاربر می‌تواند خرید اقساطی انجام دهد (true اگر اعتبار > 0)

---

## 2. CRUD برای تنظیم نرخ سود ماهانه

### 1.1 دریافت نرخ سود ماهانه

**Endpoint:** `GET /api/settings/installment-interest-rate`

**Authentication:** نیاز به احراز هویت دارد (`auth:sanctum`)

**Response:**
```json
{
  "key": "installment_monthly_interest_rate",
  "value": "2",
  "rate": 2,
  "rate_percent": "2%"
}
```

### 1.2 تنظیم نرخ سود ماهانه

**Endpoint:** `POST /api/settings/installment-interest-rate`  
**یا:** `PUT /api/settings/installment-interest-rate`

**Authentication:** نیاز به احراز هویت دارد (`auth:sanctum`)

**Request Body:**
```json
{
  "rate": 2
}
```

**Validation:**
- `rate`: الزامی، عددی، حداقل 0، حداکثر 100

**Response:**
```json
{
  "message": "نرخ سود ماهانه با موفقیت تنظیم شد",
  "key": "installment_monthly_interest_rate",
  "value": "2",
  "rate": 2,
  "rate_percent": "2%"
}
```

**مثال استفاده:**
```bash
# تنظیم نرخ سود 2 درصد
curl -X POST http://api.example.com/api/settings/installment-interest-rate \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"rate": 2}'

# غیرفعال کردن سود (0 درصد)
curl -X POST http://api.example.com/api/settings/installment-interest-rate \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"rate": 0}'
```

---

## 3. محاسبه مبلغ اقساط

### 3.1 محاسبه مبلغ اقساط

**Endpoint:** `POST /api/purchased-products/calculate-installments`

**Authentication:** نیاز به احراز هویت ندارد (Public)

**Request Body:**
```json
{
  "total_amount": 1000000,
  "installment_count": 4,
  "phone": "09120000000"
}
```

**Validation:**
- `total_amount`: الزامی، عددی، حداقل 0
- `installment_count`: الزامی، عدد صحیح، حداقل 2، حداکثر 24
- `phone`: اختیاری، شماره تلفن 11 رقمی (برای چک اعتبار)

**Response (با سود و با چک اعتبار - اعتبار کافی):**
```json
{
  "total_amount": 1000000,
  "installment_count": 4,
  "monthly_interest_rate": 2,
  "total_interest": 50000,
  "final_total_amount": 1050000,
  "installment_amount": 262500,
  "phone": "09120000000",
  "user_credit": 2000000,
  "has_enough_credit": true,
  "installment_details": [
    {
      "month": 1,
      "remaining_amount": 1000000,
      "interest": 20000,
      "base_payment": 250000
    },
    {
      "month": 2,
      "remaining_amount": 750000,
      "interest": 15000,
      "base_payment": 250000
    },
    {
      "month": 3,
      "remaining_amount": 500000,
      "interest": 10000,
      "base_payment": 250000
    },
    {
      "month": 4,
      "remaining_amount": 250000,
      "interest": 5000,
      "base_payment": 250000
    }
  ]
}
```

**Response (با سود و با چک اعتبار - اعتبار کافی نیست):**
```json
{
  "total_amount": 1000000,
  "installment_count": 4,
  "monthly_interest_rate": 2,
  "total_interest": 50000,
  "final_total_amount": 1050000,
  "installment_amount": 262500,
  "phone": "09120000000",
  "user_credit": 500000,
  "has_enough_credit": false,
  "credit_shortage": 550000,
  "error": "اعتبار کاربر کافی نیست. اعتبار مورد نیاز: 1,050,000 تومان، اعتبار موجود: 500,000 تومان",
  "installment_details": [
    {
      "month": 1,
      "remaining_amount": 1000000,
      "interest": 20000,
      "base_payment": 250000
    },
    {
      "month": 2,
      "remaining_amount": 750000,
      "interest": 15000,
      "base_payment": 250000
    },
    {
      "month": 3,
      "remaining_amount": 500000,
      "interest": 10000,
      "base_payment": 250000
    },
    {
      "month": 4,
      "remaining_amount": 250000,
      "interest": 5000,
      "base_payment": 250000
    }
  ]
}
```

**Status Code:**
- `200`: محاسبه موفق (اعتبار کافی است یا phone ارسال نشده)
- `400`: اعتبار کافی نیست (فقط در صورت ارسال phone)

**Response (بدون سود - نرخ سود 0):**
```json
{
  "total_amount": 1000000,
  "installment_count": 4,
  "monthly_interest_rate": 0,
  "total_interest": 0,
  "final_total_amount": 1000000,
  "installment_amount": 250000,
  "installment_details": [
    {
      "month": 1,
      "remaining_amount": 1000000,
      "interest": 0,
      "base_payment": 250000
    },
    {
      "month": 2,
      "remaining_amount": 750000,
      "interest": 0,
      "base_payment": 250000
    },
    {
      "month": 3,
      "remaining_amount": 500000,
      "interest": 0,
      "base_payment": 250000
    },
    {
      "month": 4,
      "remaining_amount": 250000,
      "interest": 0,
      "base_payment": 250000
    }
  ]
}
```

**مثال استفاده:**
```bash
# محاسبه بدون چک اعتبار
curl -X POST http://api.example.com/api/purchased-products/calculate-installments \
  -H "Content-Type: application/json" \
  -d '{
    "total_amount": 1000000,
    "installment_count": 4
  }'

# محاسبه با چک اعتبار
curl -X POST http://api.example.com/api/purchased-products/calculate-installments \
  -H "Content-Type: application/json" \
  -d '{
    "total_amount": 1000000,
    "installment_count": 4,
    "phone": "09120000000"
  }'
```

---

## توضیحات فیلدهای Response

### فیلدهای اصلی:
- `total_amount`: مبلغ اصلی خرید (بدون سود)
- `installment_count`: تعداد اقساط
- `monthly_interest_rate`: نرخ سود ماهانه (درصد)
- `total_interest`: مجموع سود محاسبه شده
- `final_total_amount`: مبلغ کل با سود
- `installment_amount`: مبلغ هر قسط (با سود)

### فیلدهای جزئیات هر ماه:
- `month`: شماره ماه (1, 2, 3, ...)
- `remaining_amount`: مبلغ مانده در ابتدای آن ماه
- `interest`: سود تعلق گرفته در آن ماه
- `base_payment`: مبلغ پایه قسط (بدون سود)

### فیلدهای اعتبار (در صورت ارسال phone):
- `phone`: شماره تلفن کاربر
- `user_credit`: اعتبار فعلی کاربر
- `has_enough_credit`: آیا اعتبار کافی است (true/false)
- `credit_shortage`: کمبود اعتبار (فقط در صورت ناکافی بودن)
- `error`: پیام خطا (فقط در صورت ناکافی بودن)

**نکته مهم:** اعتبار کاربر باید به اندازه `final_total_amount` (مبلغ کل با سود) باشد.

---

## مثال محاسبه

**ورودی:**
- مبلغ خرید: 1,000,000 تومان
- تعداد اقساط: 4 ماه
- نرخ سود: 2%

**محاسبه:**
1. ماه 1: مانده = 1,000,000، سود = 20,000
2. ماه 2: مانده = 750,000، سود = 15,000
3. ماه 3: مانده = 500,000، سود = 10,000
4. ماه 4: مانده = 250,000، سود = 5,000

**نتیجه:**
- مجموع سود: 50,000 تومان
- مبلغ کل: 1,050,000 تومان
- مبلغ هر قسط: 262,500 تومان

