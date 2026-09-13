# SSL CA bundle

اگر خطای `cURL error 60: SSL certificate problem` دیدید:

1. فایل `cacert.pem` باید همین‌جا باشد (از https://curl.se/ca/cacert.pem).
2. یا در `.env` سرور:

```env
HTTP_CA_BUNDLE=C:\path\to\cacert.pem
```

3. یا موقتاً (فقط در صورت نیاز):

```env
HTTP_SSL_VERIFY=false
```
