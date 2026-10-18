# Rate limit لبه (nginx)

فایل‌ها را روی سرور API کپی کنید:

1. در بلوک `http { ... }`:

```nginx
include /path/to/atelier/deploy/nginx/rate-limit.conf;
```

2. داخل `server` مربوط به `api.webinoo-plus.ir`، **قبل از** `location /` عمومی، محتوای  
   `api-locations-rate-limit.conf` را قرار دهید (یا `include` کنید).

3. تست و اعمال:

```bash
nginx -t && systemctl reload nginx
```

پاسخ overload: **HTTP 429**.

اگر الان یک `location ~ \.php$` دارید و `try_files` جدا کار نمی‌کند، فقط خطوط `limit_req` / `client_max_body_size` را داخل locationهای موجود کپی کنید و `try_files` را مطابق کانفیگ فعلی نگه دارید.

## Cloudflare (پیشنهادی روی لایهٔ CDN)

در Security → WAF → Rate limiting rules:

| Rule | Match | Rate | Action |
|------|--------|------|--------|
| SMS | URI Path contains `/send-code` or `/confirmation-code/create` or `/send-phone-code` | 5 / 1 min / IP | Block |
| Login | URI Path contains `/api/auth/login` or `/customer/login` or `/oil/login` | 10 / 1 min / IP | Block |
| Table order | URI Path contains `/table-order` | 20 / 1 min / IP | Managed Challenge |
| Heavy | URI Path contains `/api/reports` or `/api/product-all` | 30 / 1 min / IP | Block |

IP واقعی کلاینت فقط وقتی درست است که `TRUSTED_PROXIES` در `.env` تنظیم شده باشد (nginx محلی یا Cloudflare).
