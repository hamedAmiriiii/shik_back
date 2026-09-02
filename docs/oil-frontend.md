# اسناد فرانت — تعویض روغن

پایه: `/api/oil`  
لاگین: `Authorization: Bearer {token}`  
صفحهٔ وب: `/oil`

اگر جدول ساخته نشده باشد پاسخ **۵۰۳** یا **۴۲۲** با `message` فارسی است.

SQL لازم روی دیتابیس موجود:

- `database/sql/add_project_type_and_oil_visits_manual.sql`
- `database/sql/add_oil_visits_notes_manual.sql`
- `database/sql/create_oil_products_manual.sql`
- `database/sql/add_oil_product_prices_manual.sql`

---

## انواع قلم (ثابت)

کمبوهای ثبت تعویض از این سه `kind` ساخته می‌شوند. خود فرانت kind جدید نسازد.

| `kind` | `kind_label` |
|--------|----------------|
| `oil` | روغن |
| `air_filter` | فیلتر هوا |
| `oil_filter` | فیلتر روغن |

هر فروشگاه برای هر kind چند `product` تعریف می‌کند (مثلاً بهران ۱۰W۴۰).

---

## محصول

```json
{
  "id": 12,
  "kind": "oil",
  "kind_label": "روغن",
  "name": "بهران 10W40",
  "purchase_price": 180000,
  "sale_price": 250000,
  "is_active": true,
  "sort_order": 1
}
```

`purchase_price` بهای خرید، `sale_price` قیمت فروش به مشتری. واحد تومان. پیش‌فرض ۰.

### `GET /api/oil/products`

Query:

- `include_inactive=1` — غیرفعال‌ها هم بیایند (صفحهٔ تعریف محصول)
- بدون query فقط فعال‌ها (کمبوی ثبت تعویض)

۲۰۰:

```json
{
  "kinds": [
    {
      "kind": "oil",
      "kind_label": "روغن",
      "products": [{ "id": 12, "kind": "oil", "kind_label": "روغن", "name": "بهران 10W40", "is_active": true, "sort_order": 1 }]
    },
    { "kind": "air_filter", "kind_label": "فیلتر هوا", "products": [] },
    { "kind": "oil_filter", "kind_label": "فیلتر روغن", "products": [] }
  ],
  "data": [
    { "id": 12, "kind": "oil", "kind_label": "روغن", "name": "بهران 10W40", "is_active": true, "sort_order": 1 }
  ]
}
```

برای سه کمبو از `kinds` استفاده کنید. `data` همان لیست تخت است.

### `POST /api/oil/products` — ۲۰۱

```json
{ "kind": "oil", "name": "بهران 10W40", "purchase_price": 180000, "sale_price": 250000 }
```

`kind` یکی از سه مقدار بالا، `name` حداکثر ۱۲۰ کاراکتر.

```json
{
  "message": "محصول اضافه شد.",
  "data": { "id": 12, "kind": "oil", "kind_label": "روغن", "name": "بهران 10W40", "purchase_price": 180000, "sale_price": 250000, "is_active": true, "sort_order": 1 }
}
```

۴۲۲ اگر نام تکراری فعال برای همان kind باشد.

### `PATCH /api/oil/products/{id}` — ۲۰۰

```json
{ "name": "بهران 20W50", "purchase_price": 190000, "sale_price": 260000, "is_active": true }
```

هر دو فیلد اختیاری‌اند. برای برگرداندن محصول حذف‌شده: `{ "is_active": true }`.

### `DELETE /api/oil/products/{id}` — ۲۰۰

- اگر در سابقه‌ای استفاده شده باشد سخت حذف نمی‌شود؛ `is_active: false` می‌شود و در کمبو نمی‌آید، در سابقه می‌ماند.
- اگر هیچ سابقه‌ای نداشته باشد حذف واقعی است؛ `data` ندارد.

```json
{
  "message": "محصول از لیست انتخاب برداشته شد. در سوابق قبلی باقی می‌ماند.",
  "data": { "id": 12, "is_active": false }
}
```

---

## مراجعه (visit)

```json
{
  "id": 88,
  "plate": "12ب34522",
  "plate_display": "12 ب 345 ایران 22",
  "plate_parts": {
    "serial": "12",
    "letter": "ب",
    "middle": "345",
    "province": "22"
  },
  "phone": "09121234567",
  "km": 45000,
  "next_km": 50000,
  "notes": "شستشوی موتور",
  "items": [
    { "kind": "oil", "kind_label": "روغن", "oil_product_id": 12, "name": "بهران 10W40", "purchase_price": 180000, "sale_price": 250000 },
    { "kind": "air_filter", "kind_label": "فیلتر هوا", "oil_product_id": 20, "name": "سرکان", "purchase_price": 40000, "sale_price": 70000 }
  ],
  "sale_amount": 320000,
  "cost_amount": 220000,
  "profit": 100000,
  "purchase_id": 901,
  "sms_sent": true,
  "sms_error": null,
  "created_at": "2026-09-02 16:20:00",
  "created_at_jalali": "1405/06/11 16:20"
}
```

`notes` اگر خالی باشد `null` است. `items` حداکثر یکی برای هر kind. `name` اسنپ‌شات زمان ثبت است (اگر بعداً محصول عوض/حذف شود سابقه همان متن را نشان می‌دهد). `oil_product_id` ممکن است `null` شود اگر محصول سخت حذف شده باشد.

لیست مشتریان همان آبجکت را دارد به‌علاوه `visit_count`.

---

## مشتریان و ثبت تعویض

همه با توکن.

### `GET /api/oil/customers`

Query: `q` (پلاک یا موبایل)، `per_page` (۱–۵۰، پیش‌فرض ۳۰)

پاسخ صفحه‌بندی لاراول: `data[]` هر ردیف آخرین مراجعهٔ آن پلاک + `visit_count`.

### `GET /api/oil/customers/{plate}`

`plate` همان `plate` فشرده (مثلاً `12ب34522`). URL-encode شود.

```json
{
  "customer": { "...visit", "visit_count": 3 },
  "visits": [ { "...visit" } ]
}
```

`customer` آخرین مراجعه است؛ کمبوی «تعویض جدید» را از `customer.items` پر کنید.

۴۰۴ اگر پلاک در این فروشگاه نباشد.

### `GET /api/oil/visits/lookup`

یکی از این دو:

- `?plate=12ب34522` یا متن پلاک
- `?phone=09121234567`

```json
{ "found": true, "visit": { "...آخرین مراجعه" } }
```

یا `{ "found": false }`.

اگر `found` بود تلفن، `items` و متن «قبلاً آمده» را از `visit` پر کنید.

### `POST /api/oil/visits` — ۲۰۱

پلاک یا با `plate` یا با چهار تکه:

```json
{
  "serial": "12",
  "letter": "ب",
  "middle": "345",
  "province": "22",
  "phone": "09121234567",
  "km": 45000,
  "next_km": 50000,
  "notes": "شستشوی موتور",
  "oil_product_id": 12,
  "air_filter_product_id": 20,
  "oil_filter_product_id": 21
}
```

| فیلد | اجباری | توضیح |
|------|---------|--------|
| `phone` | بله | موبایل ۱۱ رقمی ایران |
| `km` | بله | کیلومتر فعلی |
| پلاک | بله | `plate` یا `serial`+`letter`+`middle`+`province` |
| `next_km` | نه | خالی = `km + oil_interval_km` فروشگاه |
| `notes` | نه | حداکثر ۱۰۰۰ |
| `oil_product_id` | نه | باید `kind=oil` همین فروشگاه باشد |
| `air_filter_product_id` | نه | `kind=air_filter` |
| `oil_filter_product_id` | نه | `kind=oil_filter` |

محصول‌ها در پیامک خوش‌آمد نیستند؛ فقط در سابقه می‌مانند.

اگر اقلام `sale_price` داشته باشند، همان لحظه یک فروش نقدی در جدول `purchases` فروشگاه ساخته می‌شود (سود = فروش − بهای خرید). گزارش از همین فاکتورهاست.

### `GET /api/oil/reports`

با توکن. فروش / بها / سود همان روال گزارش فروشگاه:

```json
{
  "today": { "sales": 320000, "cost": 220000, "profit": 100000 },
  "yesterday": { "sales": 0, "cost": 0, "profit": 0 },
  "week": { "sales": 320000, "cost": 220000, "profit": 100000 },
  "month": { "sales": 320000, "cost": 220000, "profit": 100000 },
  "last_month": { "sales": 0, "cost": 0, "profit": 0 },
  "year": { "sales": 320000, "cost": 220000, "profit": 100000 }
}
```

---

بعد از ثبت، **دو پیامک جدا** می‌رود (لینک را با خوش‌آمد قاطی نکنید؛ پیامک لینک‌دار گاهی فیلتر می‌شود):

1. خوش‌آمد بدون لینک
2. فقط آدرس سابقه: `https://webinoo-plus.ir/oilservice/09xxxxxxxxx`

پایهٔ لینک از `OIL_PUBLIC_BASE_URL` است.

```json
{
  "message": "ثبت شد و پیامک خوش‌آمد و لینک سابقه ارسال گردید.",
  "visit": { "...visit با items" },
  "sms_sent": true,
  "sms_error": null,
  "link_sms_sent": true,
  "history_url": "https://webinoo-plus.ir/oilservice/09399166196"
}
```

اگر پیامک نرود باز ۲۰۱ است.

---

## سابقهٔ مشتری بدون لاگین

بدون توکن. از شمارهٔ مسیر صفحه:

`GET /api/oil/public/history/{phone}`

مثال برای `http://localhost:3000/oilservice/09399166196`:

`GET /api/oil/public/history/09399166196`

۲۰۰:

```json
{
  "phone": "09399166196",
  "cars": [
    {
      "plate_display": "12 ب 345 ایران 22",
      "visits": [
        {
          "shop_name": "تعویض روغن برادران",
          "km": 45000,
          "next_km": 50000,
          "notes": "شستشوی موتور",
          "items": [
            { "kind_label": "روغن", "name": "بهران 10W40" }
          ],
          "created_at_jalali": "1405/06/11 16:20"
        }
      ]
    }
  ]
}
```

شماره نامعتبر: ۴۲۲. اگر سابقه‌ای نباشد `cars` خالی است.

---

## پیشنهاد UI

1. تنظیمات → صفحهٔ «روغن و فیلترها»: سه بلوک از `GET /products?include_inactive=1`، افزودن با `POST`، حذف با `DELETE`، برگرداندن با `PATCH { is_active: true }`.
2. فرم ثبت تعویض: سه `<select>` از محصولات فعال. گزینهٔ خالی = انتخاب‌نشده.
3. اگر پلاک تکراری بود (`lookup` یا مشتری): `select.value = item.oil_product_id` برای هر kind.
4. سابقه: `items` را مثل «روغن بهران 10W40 · فیلتر هوا سرکان» نشان بدهید؛ `notes` جدا زیرش.
5. کمبو اگر خالی بود لینک به تعریف محصول؛ ثبت بدون انتخاب محصول مجاز است.

حروف پلاک در `window.OIL.letters` روی صفحهٔ `/oil` هست.

---

## ورود و فروشگاه

بدون توکن:

- `POST /api/oil/register/send-code` `{ "phone": "09xxxxxxxxx" }` — ۲۰۱ یا ۴۲۹ با `retry_after_seconds`
- `POST /api/oil/register` `{ name, last_name, phone, password, shop_name, verification_code }` — توکن در پاسخ
- `POST /api/oil/login` `{ "username": "09xxxxxxxxx", "password": "..." }`

با توکن:

- `GET /api/oil/me`
- `PATCH /api/oil/shop` `{ "shop_name", "oil_interval_km" }` — فاصله کیلومتر ۱۰۰۰ تا ۳۰۰۰۰
- `POST /api/oil/logout`

نشست (`login` / `register` / `me` / `shop`):

```json
{
  "token": "…فقط login و register…",
  "project_type": "oil",
  "user": { },
  "shop": {
    "id": 1,
    "name": "تعویض روغن برادران",
    "code": "oil-...",
    "oil_interval_km": 5000,
    "project_type": "oil"
  },
  "shop_access": { },
  "sms": { "balance": 50 }
}
```

`next_km` پیش‌فرض = `km + shop.oil_interval_km`.

---

## پیامک

با توکن:

- `GET /api/oil/sms-quota` — موجودی
- `GET /api/oil/sms-packages`
- `POST /api/oil/sms-packages/{id}/purchase`
- `GET /api/oil/sms-package-orders?per_page=20`
- `GET /api/oil/reminders` — صفحه‌بندی لاگ یادآوری
- `POST /api/oil/reminders/run` — بررسی نوبت و ارسال

یادآوری:

```json
{
  "id": 1,
  "oil_visit_id": 88,
  "plate": "12ب34522",
  "plate_display": "12 ب 345 ایران 22",
  "phone": "09121234567",
  "next_km": 50000,
  "estimated_due_on": "2026-09-10",
  "estimated_due_on_jalali": "1405/06/19",
  "days_until_due": 8,
  "message": "نوبت تعویض روغن نزدیک است\n…",
  "sms_sent": true,
  "sms_error": null,
  "created_at": "2026-09-02 16:20:00",
  "created_at_jalali": "1405/06/11 16:20"
}
```

---

## خطاهای مشترک

| وضعیت | معنی |
|--------|------|
| ۴۰۱ | توکن نیست / منقضی |
| ۴۰۳ | حساب فروشگاه است نه تعویض روغن |
| ۴۰۴ | مشتری/محصول مال این فروشگاه نیست |
| ۴۲۲ | اعتبارسنجی؛ `message` را نشان بدهید |
| ۵۰۳ | جدول SQL اجرا نشده |
