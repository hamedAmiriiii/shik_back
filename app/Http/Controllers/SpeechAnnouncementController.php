<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SpeechAnnouncementController extends Controller
{
    /**
     * GET /api/speech/announcement?text=...
     * پروکسی TTS فارسی برای اعلان سفارش — Chrome/Windows اغلب صدای فارسی محلی ندارد.
     */
    public function speak(Request $request)
    {
        $text = trim((string) $request->query('text', ''));
        if ($text === '') {
            return response()->json(['message' => 'متن اعلان خالی است.'], 422);
        }
        if (mb_strlen($text) > 300) {
            return response()->json(['message' => 'متن اعلان بیش از حد طولانی است.'], 422);
        }

        $audio = $this->fetchPersianSpeech($text);
        if ($audio === null) {
            return response()->json(['message' => 'سرویس گفتار در دسترس نیست.'], 502);
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function fetchPersianSpeech(string $text): ?string
    {
        $clients = ['tw-ob', 'gtx', 'dict-chromeex'];

        foreach ($clients as $client) {
            $url = 'https://translate.google.com/translate_tts?' . http_build_query([
                'ie' => 'UTF-8',
                'client' => $client,
                'tl' => 'fa',
                'q' => $text,
            ]);

            try {
                $response = Http::timeout(12)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        'Referer' => 'https://translate.google.com/',
                        'Accept' => '*/*',
                    ])
                    ->get($url);

                if (!$response->successful()) {
                    continue;
                }

                $body = $response->body();
                if (strlen($body) > 256) {
                    return $body;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }
}
