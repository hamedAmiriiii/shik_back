<?php


namespace App\Tools;

use Illuminate\Support\Facades\Http;

class SmsTools
{

    public const API_TOKEN = "sa345478720:L2ZSDrZ7lEYzxark92MWhH2mveIUxQ7KABqI";

    public static function getBalance()
    {
        $response = Http::get('http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/account/balance.json');
        return $response->json();
    }

    public static function sendSms(string $receivers, string $text)
    {
        $response = Http::get('http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/sms/send.json', [
            'gateway' => '1000081290',
            'to' => $receivers,
            'text' => $text
        ]);

        return $response->json();
    }

}
