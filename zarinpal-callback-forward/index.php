<?php
/**
 * دامنهٔ ثبت‌شده در زرین‌پال: webinoo-plus.ir
 * این فایل را اینجا بگذارید:
 * /home/webinop1/public_html/webinoo-plus.ir/api/payments/zarinpal/callback/index.php
 *
 * روش ساده‌تر: فایل public/zarinpal-callback.html فرانت را در ریشهٔ سایت آپلود کنید.
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'https://api.webinoplus.ir/api/payments/zarinpal/callback';
if ($query !== '') {
    $target .= '?'.$query;
}

header('Location: '.$target, true, 302);
exit;
