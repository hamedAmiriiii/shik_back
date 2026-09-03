<?php

namespace App\Services;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Models\Atelier;
use App\Models\OilReminderSms;
use App\Models\OilVisit;
use App\Support\OilSms;
use App\Support\ProjectType;
use App\Tools\SmsTools;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class OilReminderDispatcher
{
    /** میانگین ثابت همهٔ ماشین‌ها */
    public const KM_PER_YEAR = 27000;

    public const LOOKAHEAD_DAYS = 10;

    /** اگر API بعد از نوبت صدا زده شد، تا این تعداد روز تأخیر هنوز یک‌بار یادآوری برود */
    public const MAX_OVERDUE_DAYS = 90;

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
        $defaultKmPerDay = $kmPerYear / 365;
        $lookahead = max(1, (int) config('oil.reminder_lookahead_days', self::LOOKAHEAD_DAYS));
        $maxOverdue = max(0, (int) config('oil.reminder_max_overdue_days', self::MAX_OVERDUE_DAYS));

        $atelierIds = self::oilAtelierIds($atelierId);
        $scanned = 0;
        $due = 0;
        $sent = 0;
        $skipped = 0;
        $skippedOther = 0;
        $failed = 0;
        $inspected = [];

        if ($atelierIds === []) {
            return self::summary($kmPerYear, $defaultKmPerDay, $lookahead, $maxOverdue, 0, 0, 0, 0, 0, 0, []);
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

        $histories = self::historiesByPlate($visits->pluck('plate')->unique()->filter()->all());

        $shopNames = Atelier::query()
            ->whereIn('id', $atelierIds)
            ->pluck('name', 'id');

        foreach ($visits as $visit) {
            $scanned++;
            $history = $histories->get($visit->plate, collect());
            $forecast = self::forecast($visit, $defaultKmPerDay);
            $row = [
                'oil_visit_id' => (int) $visit->id,
                'plate' => $visit->plate_display,
                'phone' => $visit->phone,
                'km' => (int) $visit->km,
                'next_km' => (int) $visit->next_km,
                'remaining_km' => is_array($forecast) ? ($forecast['remaining_km'] ?? null) : null,
                'interval_days' => is_array($forecast) ? ($forecast['interval_days'] ?? null) : null,
                'days_until_due' => is_array($forecast) ? ($forecast['days_until_due'] ?? null) : null,
                'estimated_due_on' => is_array($forecast) ? ($forecast['estimated_due_on'] ?? null) : null,
                'action' => null,
            ];

            if (isset($alreadyMap[$visit->id])) {
                $skipped++;
                $row['action'] = 'already_sent';
                $inspected[] = $row;
                continue;
            }

            if (self::plateMovedToAnotherShop($visit, $history)) {
                $skippedOther++;
                $row['action'] = 'other_shop_has_newer_visit';
                $inspected[] = $row;
                continue;
            }

            if ($forecast === null) {
                $row['action'] = 'no_forecast';
                $inspected[] = $row;
                continue;
            }
            if ($forecast['days_until_due'] > $lookahead) {
                $row['action'] = 'too_early';
                $inspected[] = $row;
                continue;
            }
            if ($forecast['days_until_due'] < -$maxOverdue) {
                $row['action'] = 'too_overdue';
                $inspected[] = $row;
                continue;
            }

            $due++;
            $shopName = trim((string) ($shopNames[$visit->atelier_id] ?? ''));
            if ($shopName === '') {
                $shopName = 'تعویض روغن';
            }
            $message = self::message(
                $shopName,
                $visit->plate_display,
                (int) $visit->next_km,
                (int) $forecast['days_until_due'],
                $forecast['estimated_due_on']
            );

            try {
                $log = OilReminderSms::query()->where('oil_visit_id', $visit->id)->first();
                if ($log && $log->sms_sent) {
                    $skipped++;
                    $row['action'] = 'already_sent';
                    $inspected[] = $row;
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
                $row['action'] = 'db_skip';
                $inspected[] = $row;
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
                $row['action'] = 'sent';
            } else {
                $failed++;
                $row['action'] = 'send_failed';
                $row['error'] = $error;
            }
            $inspected[] = $row;
        }

        return self::summary($kmPerYear, $defaultKmPerDay, $lookahead, $maxOverdue, $scanned, $due, $sent, $skipped, $failed, $skippedOther, $inspected);
    }

    /**
     * اگر آخرین مراجعهٔ این پلاک در مغازهٔ دیگری باشد، این مغازه دیگر یادآوری ندهد.
     */
    public static function plateMovedToAnotherShop(OilVisit $visit, Collection $history): bool
    {
        if ($history->isEmpty()) {
            return false;
        }
        $latest = $history->sortBy([
            ['created_at', 'asc'],
            ['id', 'asc'],
        ])->last();
        if (! $latest) {
            return false;
        }

        return (int) $latest->id !== (int) $visit->id;
    }

    /**
     * از تاریخ ثبت: (next_km − km) با میانگین ۲۷۰۰۰ کیلومتر/سال چند روز طول می‌کشد.
     *
     * @return array{days_until_due: int, estimated_due_on: string, remaining_km: int, interval_days: int}|null
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
                'remaining_km' => $remaining,
                'interval_days' => 0,
            ];
        }

        $intervalDays = $remaining / $kmPerDay;
        $elapsed = $visit->created_at->floatDiffInDays(now());
        $daysUntil = (int) round($intervalDays - $elapsed);
        $dueOn = $visit->created_at->copy()->addSeconds((int) round($intervalDays * 86400));

        return [
            'days_until_due' => $daysUntil,
            'estimated_due_on' => $dueOn->toDateString(),
            'remaining_km' => $remaining,
            'interval_days' => (int) round($intervalDays),
        ];
    }

    public static function message(string $shopName, string $plateDisplay, int $nextKm, int $daysUntil, $dueOn = null): string
    {
        $when = $daysUntil <= 0 ? 'الان' : 'حدود '.abs($daysUntil).' روز دیگر';

        return OilSms::appendDate(
            "نوبت تعویض روغن نزدیک است\n{$shopName}\nپلاک {$plateDisplay}\n{$when} — کیلومتر {$nextKm}",
            $dueOn
        );
    }

    /**
     * @param  array<int, string>  $plates
     * @return Collection<string, Collection<int, OilVisit>>
     */
    private static function historiesByPlate(array $plates): Collection
    {
        if ($plates === []) {
            return collect();
        }

        return OilVisit::query()
            ->whereIn('plate', $plates)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('plate');
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
        int $maxOverdue,
        int $scanned,
        int $due,
        int $sent,
        int $skipped,
        int $failed,
        int $skippedOther,
        array $inspected
    ): array {
        $daysFor = static fn (int $km) => $kmPerDay > 0 ? (int) round($km / $kmPerDay) : null;

        return [
            'km_per_year' => $kmPerYear,
            'km_per_day' => round($kmPerDay, 2),
            'lookahead_days' => $lookahead,
            'max_overdue_days' => $maxOverdue,
            'days_for_5000_km' => $daysFor(5000),
            'days_for_7000_km' => $daysFor(7000),
            'typical_days_between_changes' => $daysFor(5000),
            'scanned' => $scanned,
            'due' => $due,
            'sent' => $sent,
            'skipped_already_sent' => $skipped,
            'skipped_other_shop' => $skippedOther,
            'failed' => $failed,
            'inspected' => $inspected,
            'message' => $sent > 0
                ? $sent.' پیامک یادآوری ارسال شد.'
                : 'ماشینی در پنجرهٔ یادآوری بدون پیامک قبلی نبود.',
        ];
    }
}
