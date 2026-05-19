<?php


namespace App\Tools;

use App\Models\Atelier;
use App\Models\LogSms;
use App\Models\ShopSmsLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Kavenegar;

class SmsTools
{

    public const API_TOKEN = "sa345478720:L2ZSDrZ7lEYzxark92MWhH2mveIUxQ7KABqI";

    /**
     * نام نمایشی فروشگاه برای ابتدای پیامک (نام رکورد ateliers).
     */
    public static function shopSmsBrand(?int $atelierId): string
    {
        if ($atelierId === null || $atelierId === 0) {
            return 'فروشگاه';
        }

        static $cache = [];
        if (! array_key_exists($atelierId, $cache)) {
            $name = trim((string) Atelier::where('id', $atelierId)->value('name'));
            $cache[$atelierId] = $name !== '' ? $name : 'فروشگاه';
        }

        return $cache[$atelierId];
    }

    public static function getBalance()
    {
        $response = Http::get('http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/account/balance.json');
        return $response->json();
    }

    public static function sendSms(string $receivers, string $text)
    {
        $response = Http::get('http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/sms/send.json', [
            'gateway' => '10003000207',
            'to' => $receivers,
            'text' => $text . " \n لغو11"
        ]);

        LogSms::create([
            "text" => $text,
            "number" => '1000528554',
            "receivers" => $receivers,
            "creator_id" => Auth::id()
        ]);

        return $response->json();
    }

    /**
     * ارسال پیامک برای فروشگاه و ثبت در جدول shop_sms_logs
     * 
     * @param string $phone شماره تلفن گیرنده
     * @param string $message متن پیام
     * @param string|null $purchaseId ID خرید (اختیاری)
     * @param float|null $creditAmount مبلغ اعتبار (اختیاری)
     * @param string $smsType نوع پیامک (پیش‌فرض: purchase)
     * @param int|null $atelierId شناسه فروشگاه
     * @return array
     */
    public static function sendShopSms(string $phone, string $message, ?string $purchaseId = null, ?float $creditAmount = null, string $smsType = 'purchase', ?int $atelierId = null)
    {
        $response = Http::get('http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/sms/send.json', [
            'gateway' => '10003000207',
            'to' => $phone,
            'text' => $message . " \n لغو11"
        ]);

        ShopSmsLog::create([
            'atelier_id' => $atelierId,
            'phone' => $phone,
            'message' => $message,
            'purchase_id' => $purchaseId,
            'credit_amount' => $creditAmount,
            'sms_type' => $smsType,
        ]);

        return $response->json();
    }

    public static function sendSmsK(string $receivers, string $text)
    {
        try {
            $result = Kavenegar::Send("500031430320", $receivers, $text);
            if ($result) {
                foreach ($result as $r) {
                    echo "messageid = $r->messageid";
                    echo "message = $r->message";
                    echo "status = $r->status";
                    echo "statustext = $r->statustext";
                    echo "sender = $r->sender";
                    echo "receptor = $r->receptor";
                    echo "date = $r->date";
                    echo "cost = $r->cost";
                }
            }
            return $result;
        } catch (\Kavenegar\Exceptions\ApiException $e) {
            // در صورتی که خروجی وب سرویس 200 نباشد این خطا رخ می دهد
            echo $e->errorMessage();
        } catch
        (\Kavenegar\Exceptions\HttpException $e) {
            // در زمانی که مشکلی در برقرای ارتباط با وب سرویس وجود داشته باشد این خطا رخ می دهد
            echo $e->errorMessage();
        }

    }
}
