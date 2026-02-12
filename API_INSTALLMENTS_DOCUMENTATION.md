# مستندات API سیستم اقساطی

## 🔐 احراز هویت
تمام endpoint های زیر نیاز به احراز هویت دارند و فقط برای ادمین‌ها قابل دسترسی هستند.
**Header مورد نیاز:** `Authorization: Bearer {token}`

---

## 1️⃣ ایجاد خرید اقساطی

### `POST /api/purchased-products`

ایجاد یک خرید جدید با امکان پرداخت نقدی یا اقساطی.

#### Request Body:
```json
{
  "phone": "09120000000",
  "products": [
    {
      "product_id": 1,
      "quantity": 2,
      "sale_price": 250000,
      "size": "M",
      "color": "black"
    }
  ],
  "payment_type": "installment",
  "installment_count": 6,
  "use_credit": false,
  "discount_amount": 0
}
```

#### فیلدها:
- `phone` (string, nullable): شماره تلفن مشتری (11 رقم)
- `products` (array, required): لیست محصولات
  - `product_id` (integer, required): شناسه محصول
  - `quantity` (integer, required): تعداد
  - `sale_price` (number, nullable): قیمت فروش (اختیاری)
  - `discount_percent` (number, nullable): درصد تخفیف (اختیاری)
  - `size` (string, nullable): سایز
  - `color` (string, nullable): رنگ
- `payment_type` (string, nullable): نوع پرداخت - `cash` یا `installment` (پیش‌فرض: `cash`)
- `installment_count` (integer, required_if:payment_type=installment): تعداد اقساط (حداقل 2، حداکثر 24)
- `use_credit` (boolean, nullable): استفاده از اعتبار مشتری
- `discount_amount` (number, nullable): مبلغ تخفیف مستقیم

#### Response (201 Created):
```json
{
  "id": 101,
  "phone": "09120000000",
  "total_amount": 500000,
  "credit_used": 0,
  "credit_earned": 0,
  "payment_type": "installment",
  "installment_count": 6,
  "installment_amount": 83333.33,
  "created_at": "1403-11-16 10:30:00",
  "purchased_products": [
    {
      "id": 201,
      "product_id": 1,
      "quantity": 2,
      "sale_price": 250000,
      "purchase_price": 150000,
      "size": "M",
      "color": "black",
      "product": {
        "id": 1,
        "name": "تی‌شرت"
      }
    }
  ],
  "installments": [
    {
      "id": 301,
      "purchase_id": 101,
      "installment_number": 1,
      "amount": 83333.33,
      "due_date": "2026-02-06",
      "is_paid": true,
      "paid_at": "2026-02-06 10:30:00",
      "notes": null
    },
    {
      "id": 302,
      "purchase_id": 101,
      "installment_number": 2,
      "amount": 83333.33,
      "due_date": "2026-03-06",
      "is_paid": false,
      "paid_at": null,
      "notes": null
    }
    // ... قسط‌های دیگر
  ]
}
```

---

## 2️⃣ لیست قسط‌های یک خرید

### `GET /api/purchased-products/{purchase}/installments`

دریافت لیست تمام قسط‌های یک خرید خاص.

#### URL Parameters:
- `purchase` (integer, required): شناسه خرید

#### Response (200 OK):
```json
{
  "purchase": {
    "id": 101,
    "phone": "09120000000",
    "total_amount": 500000,
    "payment_type": "installment",
    "installment_count": 6,
    "installment_amount": 83333.33
  },
  "installments": [
    {
      "id": 301,
      "purchase_id": 101,
      "installment_number": 1,
      "amount": 83333.33,
      "due_date": "2026-02-06",
      "due_date_jalali": "1404/11/17",
      "is_paid": true,
      "paid_at": "2026-02-06 10:30:00",
      "paid_at_jalali": "1404/11/17 10:30:00",
      "notes": null,
      "created_at": "2026-02-06 10:30:00",
      "updated_at": "2026-02-06 10:30:00"
    },
    {
      "id": 302,
      "purchase_id": 101,
      "installment_number": 2,
      "amount": 83333.33,
      "due_date": "2026-03-06",
      "due_date_jalali": "1404/12/16",
      "is_paid": false,
      "paid_at": null,
      "paid_at_jalali": null,
      "notes": null,
      "created_at": "2026-02-06 10:30:00",
      "updated_at": "2026-02-06 10:30:00"
    }
  ],
  "paid_amount": 83333.33,
  "remaining_amount": 416666.67
}
```

#### فیلدهای Response:
- `purchase`: اطلاعات خرید
- `installments`: لیست قسط‌ها (مرتب شده بر اساس شماره قسط)
- `paid_amount`: مجموع مبلغ پرداخت شده
- `remaining_amount`: مبلغ باقیمانده

---

## 3️⃣ پرداخت یک قسط

### `POST /api/purchased-products/{purchase}/installments/{installment}/pay`

علامت‌گذاری یک قسط به عنوان پرداخت شده.

#### URL Parameters:
- `purchase` (integer, required): شناسه خرید
- `installment` (integer, required): شناسه قسط

#### Request Body:
```json
{
  "notes": "پرداخت نقدی در فروشگاه"
}
```

#### فیلدها:
- `notes` (string, nullable): یادداشت پرداخت

#### Response (200 OK):
```json
{
  "message": "قسط با موفقیت پرداخت شد",
  "installment": {
    "id": 302,
    "purchase_id": 101,
    "installment_number": 2,
    "amount": 83333.33,
    "due_date": "2026-03-06",
    "is_paid": true,
    "paid_at": "2026-02-10 14:20:00",
    "notes": "پرداخت نقدی در فروشگاه",
    "purchase": {
      "id": 101,
      "phone": "09120000000",
      "total_amount": 500000
    }
  },
  "purchase": {
    "id": 101,
    "phone": "09120000000",
    "total_amount": 500000,
    "installments": [
      // لیست تمام قسط‌ها
    ]
  }
}
```

#### خطاهای ممکن:
- `400`: قسط متعلق به این خرید نیست
- `400`: قسط قبلاً پرداخت شده است
- `500`: خطا در پرداخت قسط

---

## 4️⃣ لیست قسط‌های یک مشتری

### `GET /api/installments/by-phone?phone=09120000000`

دریافت لیست تمام خریدهای اقساطی و قسط‌های یک مشتری بر اساس شماره تلفن.

#### Query Parameters:
- `phone` (string, required): شماره تلفن مشتری (11 رقم)

#### Response (200 OK):
```json
{
  "phone": "09120000000",
  "purchases": [
    {
      "id": 101,
      "phone": "09120000000",
      "total_amount": 500000,
      "payment_type": "installment",
      "installment_count": 6,
      "installment_amount": 83333.33,
      "created_at": "2026-02-06 10:30:00",
      "installments": [
        {
          "id": 301,
          "installment_number": 1,
          "amount": 83333.33,
          "due_date": "2026-02-06",
          "is_paid": true,
          "paid_at": "2026-02-06 10:30:00"
        },
        {
          "id": 302,
          "installment_number": 2,
          "amount": 83333.33,
          "due_date": "2026-03-06",
          "is_paid": false,
          "paid_at": null
        }
        // ... قسط‌های دیگر
      ]
    },
    {
      "id": 102,
      "phone": "09120000000",
      "total_amount": 300000,
      "payment_type": "installment",
      "installment_count": 3,
      "installment_amount": 100000,
      "created_at": "2026-01-20 15:00:00",
      "installments": [
        // قسط‌های این خرید
      ]
    }
  ]
}
```

#### نکات:
- فقط خریدهای با `payment_type = 'installment'` برگردانده می‌شوند
- خریدها بر اساس تاریخ ایجاد (جدیدترین اول) مرتب می‌شوند
- قسط‌های هر خرید بر اساس شماره قسط مرتب می‌شوند

---

## 5️⃣ لیست قسط‌های پرداخت نشده

### `GET /api/installments/unpaid`

دریافت لیست تمام قسط‌های پرداخت نشده (برای ادمین).

#### Query Parameters:
- `overdue` (boolean, optional): فقط قسط‌های سررسید شده
- `due_soon` (boolean, optional): فقط قسط‌هایی که در 3 روز آینده سر می‌رسند
- `per_page` (integer, optional): تعداد آیتم در هر صفحه (پیش‌فرض: 20)

#### مثال‌ها:
```
GET /api/installments/unpaid
GET /api/installments/unpaid?overdue=1
GET /api/installments/unpaid?due_soon=1
GET /api/installments/unpaid?per_page=50
```

#### Response (200 OK):
```json
{
  "data": [
    {
      "id": 302,
      "purchase_id": 101,
      "installment_number": 2,
      "amount": 83333.33,
      "due_date": "2026-03-06",
      "is_paid": false,
      "paid_at": null,
      "notes": null,
      "created_at": "2026-02-06 10:30:00",
      "updated_at": "2026-02-06 10:30:00",
      "purchase": {
        "id": 101,
        "phone": "09120000000",
        "total_amount": 500000,
        "payment_type": "installment",
        "created_at": "2026-02-06 10:30:00"
      }
    },
    {
      "id": 303,
      "purchase_id": 101,
      "installment_number": 3,
      "amount": 83333.33,
      "due_date": "2026-04-06",
      "is_paid": false,
      "paid_at": null,
      "notes": null,
      "purchase": {
        "id": 101,
        "phone": "09120000000",
        "total_amount": 500000
      }
    }
  ],
  "current_page": 1,
  "per_page": 20,
  "total": 45,
  "last_page": 3
}
```

#### نکات:
- قسط‌ها بر اساس تاریخ سررسید مرتب می‌شوند (زودترین اول)
- هر قسط شامل اطلاعات خرید (`purchase`) است
- از pagination استفاده می‌کند

---

## 📝 نکات مهم

### قسط اول:
- قسط اول به صورت خودکار در همان روز خرید پرداخت شده (`is_paid = true`) ثبت می‌شود
- تاریخ سررسید قسط اول همان روز خرید است

### قسط‌های بعدی:
- قسط‌های بعدی در ماه‌های بعدی در همان روز سر می‌رسند
- مثال: اگر خرید در روز 6 انجام شود، قسط‌های بعدی در روز 6 ماه‌های بعد سر می‌رسند

### محاسبه مبلغ قسط:
- مبلغ کل خرید به تعداد اقساط تقسیم می‌شود
- اگر تفاوتی به دلیل رند کردن وجود داشته باشد، به آخرین قسط اضافه می‌شود

### یادآوری قسط‌ها:
- سیستم به صورت خودکار 3 روز قبل از سررسید هر قسط، پیامک یادآوری ارسال می‌کند
- این کار توسط Command `installments:send-reminders` انجام می‌شود که روزانه در ساعت 10 صبح اجرا می‌شود

---

## 🔒 خطاهای احراز هویت

### 403 Forbidden:
```json
{
  "error": "این endpoint فقط برای ادمین است"
}
```
یا
```json
{
  "error": "دسترسی غیرمجاز"
}
```

### 401 Unauthorized:
```json
{
  "message": "Unauthenticated."
}
```

---

## 📊 مثال کامل: ایجاد خرید اقساطی و پرداخت قسط

### مرحله 1: ایجاد خرید اقساطی
```bash
POST /api/purchased-products
{
  "phone": "09120000000",
  "products": [
    {
      "product_id": 1,
      "quantity": 1,
      "sale_price": 600000
    }
  ],
  "payment_type": "installment",
  "installment_count": 6
}
```

### مرحله 2: مشاهده قسط‌ها
```bash
GET /api/purchased-products/101/installments
```

### مرحله 3: پرداخت قسط دوم
```bash
POST /api/purchased-products/101/installments/302/pay
{
  "notes": "پرداخت نقدی"
}
```

### مرحله 4: مشاهده قسط‌های پرداخت نشده
```bash
GET /api/installments/unpaid?due_soon=1
```

---

## 🎯 خلاصه Endpoint ها

| Method | Endpoint | توضیحات |
|--------|----------|---------|
| POST | `/api/purchased-products` | ایجاد خرید (نقدی یا اقساطی) |
| GET | `/api/purchased-products/{purchase}/installments` | لیست قسط‌های یک خرید |
| POST | `/api/purchased-products/{purchase}/installments/{installment}/pay` | پرداخت یک قسط |
| GET | `/api/installments/by-phone?phone=...` | لیست قسط‌های یک مشتری |
| GET | `/api/installments/unpaid` | لیست قسط‌های پرداخت نشده |

---

**نکته:** تمام endpoint ها نیاز به احراز هویت دارند و فقط برای ادمین‌ها قابل دسترسی هستند.

