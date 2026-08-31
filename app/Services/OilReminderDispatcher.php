<?php

namespace App\Services;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Models\Atelier;
use App\Models\OilReminderSms;
use App\Models\OilVisit;
use App\Support\ProjectType;
use App\Tools\SmsTools;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class OilReminderDispatcher
{
    /** ۲۵–۳۰ هزار کیلومتر در سال → میانگین */
    public const KM_PER_YEAR = 27500;

    public const LOOKAHEAD_DAYS = 10;

    /**
     * @return array<string, mixed>
     */
    public static function run(?int $atelierId = null): array
    {
        if (! Schema::hasTable('oil_visits') || ! Schema::hasTable('oil_reminder_sms')) {
            abort(response()->json([
                'message' => 'جدول یادآوری تعویض روغن هنوز ساخته نشده. database/sql/create_oil_reminder_sms_manual.sql را اجرا کنید.',
            ], 503));
        }

        $kmPerYear = max(1000, (int) config('oil.km_per_year', self::KM_PER_YEAR));
        $kmPerDay = $kmPerYear / 365;
        $lookahead = max(1, (int) config('oil.reminder_lookahead_days', self::LOOKAHEAD_DAYS));

        $atelierIds = self::oilAtelierIds($atelierId);
        $scanned = 0;
        $due = 0;
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        if ($atelierIds === []) {
            return self::summary($kmPerYear, $kmPerDay, $lookahead, 0, 0, 0, 0, 0);
        }

        $alreadySent = OilReminderSms::query()
            ->whereIn('atelier_id', $atelierIds)
            ->where('sms_sent', true)
            ->pluck('oil_visit_id')
            ->all();
        $alreadyMap = array_fill_keys($alreadySent, true);

        $latestIds = OilVisit::query()
            ->selectRaw('MAX(id)')
            ->whereIn('atelier_id', $atelierIds)
            ->groupBy('atelier_id', 'plate');

        $visits = OilVisit::query()
            ->whereIn('id', $latestIds)
            ->orderBy('id')
            ->get();

        $shopNames = Atelier::query()
            ->whereIn('id', $atelierIds)
            ->pluck('name', 'id');

        foreach ($visits as $visit) {
            $scanned++;
            if (isset($alreadyMap[$visit->id])) {
                $skipped++;
                continue;
            }

            $forecast = self::forecast($visit, $kmPerDay);
            if ($forecast === null) {
                continue;
            }
            if ($forecast['days_until_due'] > $lookahead || $forecast['days_until_due'] < -1) {
                continue;
            }

            $due++;
            $shopName = trim((string) ($shopNames[$visit->atelier_id] ?? ''));
            if ($shopName === '') {
                $shopName = 'تعویض روغن';
            }
            $message = self::message($shopName, $visit->plate_display, (int) $visit->next_km, (int) $forecast['days_until_due']);

            try {
                $log = OilReminderSms::query()->where('oil_visit_id', $visit->id)->first();
                if ($log && $log->sms_sent) {
                    $skipped++;
                    continue;
                }
                if (! $log) {
                    $log = OilReminderSms::create([
                        'atelier_id' => $visit->atelier_id,
                        'oil_visit_id' => $visit->id,
                        'plate' => $visit->plate,
                        'plate_display' => $visit->plate_display,
                        'phone' => $visit->phone,
                        'next_km' => $visit->next_km,
                        'estimated_due_on' => $forecast['estimated_due_on'],
                        'days_until_due' => $forecast['days_until_due'],
                        'message' => $message,
                        'sms_sent' => false,
                    ]);
                }
            } catch (QueryException $e) {
                $skipped++;
                continue;
            }

            [$ok, $error] = self::sendSms($visit->phone, $message, (int) $visit->atelier_id);
            $log->update([
                'sms_sent' => $ok,
                'sms_error' => $error,
            ]);
            if ($ok) {
                $sent++;
                $alreadyMap[$visit->id] = true;
            } else {
                $failed++;
            }
        }

        return self::summary($kmPerYear, $kmPerDay, $lookahead, $scanned, $due, $sent, $skipped, $failed);
    }

    /**
     * @return array{days_until_due: int, estimated_due_on: string}|null
     */
    public static function forecast(OilVisit $visit, float $kmPerDay): ?array
    {
        if ($kmPerDay <= 0 || ! $visit->created_at) {
            return null;
        }
        $remaining = (int) $visit->next_km - (int) $visit->km;
        if ($remaining <= 0) {
            return [
                'days_until_due' => 0,
                'estimated_due_on' => $visit->created_at->toDateString(),
            ];
        }
        $intervalDays = $remaining / $kmPerDay;
        $elapsed = $visit->created_at->diffInDays(now());
        $daysUntil = (int) round($intervalDays - $elapsed);
        $dueOn = $visit->created_at->copy()->addDays((int) round($intervalDays));

        return [
            'days_until_due' => $daysUntil,
            'estimated_due_on' => $dueOn->toDateString(),
        ];
    }

    public static function message(string $shopName, string $plateDisplay, int $nextKm, int $daysUntil): string
    {
        $when = $daysUntil <= 0 ? 'الان' : 'حدود '.abs($daysUntil).' روز دیگر';

        return "نوبت تعویض روغن نزدیک است\n{$shopName}\nپلاک {$plateDisplay}\n{$when} — کیلومتر {$nextKm}";
    }

    /**
     * @return array<int, int>
     */
    private static function oilAtelierIds(?int $atelierId): array
    {
        $q = Atelier::query();
        if (Schema::hasColumn('ateliers', 'project_type')) {
            $q->where('project_type', ProjectType::OIL);
        }
        if ($atelierId) {
            $q->where('id', $atelierId);
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private static function sendSms(string $phone, string $message, int $atelierId): array
    {
        try {
            SmsTools::sendShopSms($phone, $message, null, null, 'oil_reminder', $atelierId);

            return [true, null];
        } catch (InsufficientShopSmsQuotaException $e) {
            try {
                SmsTools::sendSms($phone, $message);

                return [true, null];
            } catch (\Throwable $fallback) {
                return [false, $e->getMessage()];
            }
        } catch (\Throwable $e) {
            return [false, 'ارسال پیامک ناموفق بود.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(
        int $kmPerYear,
        float $kmPerDay,
        int $lookahead,
        int $scanned,
        int $due,
        int $sent,
        int $skipped,
        int $failed
    ): array {
        $interval5000Days = $kmPerDay > 0 ? (int) round(5000 / $kmPerDay) : null;

        return [
            'km_per_year' => $kmPerYear,
            'km_per_day' => round($kmPerDay, 2),
            'lookahead_days' => $lookahead,
            'typical_days_between_changes' => $interval5000Days,
            'scanned' => $scanned,
            'due' => $due,
            'sent' => $sent,
            'skipped_already_sent' => $skipped,
            'failed' => $failed,
            'message' => $sent > 0
                ? $sent.' پیامک یادآوری ارسال شد.'
                : 'ماشینی در پنجرهٔ ۱۰ روزه بدون پیامک قبلی نبود.',
        ];
    }
}
