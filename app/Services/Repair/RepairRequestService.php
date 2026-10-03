<?php

namespace App\Services\Repair;

use App\Models\GatewayPayment;
use App\Models\RepairPayout;
use App\Models\RepairRequest;
use App\Models\RepairUser;
use App\Tools\ImageTools;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RepairRequestService
{
    public function __construct(protected RepairNotifier $notifier)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(RepairUser $customer, array $data): RepairRequest
    {
        $request = RepairRequest::create([
            'customer_id' => $customer->id,
            'category' => $data['category'] ?? null,
            'description' => $data['description'],
            'address' => $data['address'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'contact_name' => $data['contact_name'] ?? $customer->name,
            'contact_phone' => $data['contact_phone'] ?? $customer->phone,
            'preferred_time' => $data['preferred_time'] ?? null,
            'status' => RepairRequest::STATUS_PENDING,
        ]);

        $updates = [];
        if (! $customer->name && ! empty($data['contact_name'])) {
            $updates['name'] = $data['contact_name'];
        }
        if (! $customer->address) {
            $updates['address'] = $data['address'];
        }
        if ($updates !== []) {
            $customer->update($updates);
        }

        $this->notifier->requestCreated($request);

        return $request;
    }

    public function assign(RepairRequest $request, RepairUser $technician, ?string $adminNote = null): RepairRequest
    {
        if (! $technician->isTechnician() || ! $technician->is_active) {
            throw new RuntimeException('تعمیرکار انتخاب‌شده فعال نیست.');
        }
        if (! in_array($request->status, [
            RepairRequest::STATUS_PENDING,
            RepairRequest::STATUS_ASSIGNED,
            RepairRequest::STATUS_IN_PROGRESS,
        ], true)) {
            throw new RuntimeException('این درخواست در وضعیتی نیست که بتوان تعمیرکار را تغییر داد.');
        }

        $changed = (int) $request->technician_id !== (int) $technician->id;
        $request->fill([
            'technician_id' => $technician->id,
            'status' => $changed ? RepairRequest::STATUS_ASSIGNED : $request->status,
            'share_percent' => (float) $technician->labor_share_percent,
            'assigned_at' => $changed ? now() : $request->assigned_at,
            'started_at' => $changed ? null : $request->started_at,
        ]);
        if ($adminNote !== null) {
            $request->admin_note = $adminNote;
        }
        $request->save();

        if ($changed) {
            $this->notifier->assigned($request->fresh(['technician']));
        }

        return $request->fresh(['technician', 'customer']);
    }

    public function start(RepairRequest $request): RepairRequest
    {
        if ($request->status !== RepairRequest::STATUS_ASSIGNED) {
            throw new RuntimeException('فقط درخواست ارجاع‌شده را می‌توان شروع کرد.');
        }
        $request->update([
            'status' => RepairRequest::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $request->fresh(['technician', 'customer']);
    }

    /**
     * ثبت/ویرایش هزینه. تعمیرکار تا قبل از ارسال رسید، ادمین تا قبل از تکمیل می‌تواند ویرایش کند.
     */
    public function setCost(
        RepairRequest $request,
        int $labor,
        int $parts,
        ?string $description,
        bool $byAdmin,
        ?float $sharePercent = null
    ): RepairRequest {
        $allowed = [
            RepairRequest::STATUS_ASSIGNED,
            RepairRequest::STATUS_IN_PROGRESS,
            RepairRequest::STATUS_INVOICED,
        ];
        if ($byAdmin) {
            $allowed[] = RepairRequest::STATUS_PAYMENT_REVIEW;
        }
        if (! in_array($request->status, $allowed, true)) {
            throw new RuntimeException('در این وضعیت امکان ثبت هزینه نیست.');
        }
        if (! $request->technician_id) {
            throw new RuntimeException('ابتدا تعمیرکار را ارجاع دهید.');
        }
        if ($labor < 0 || $parts < 0 || ($labor + $parts) <= 0) {
            throw new RuntimeException('مبلغ هزینه معتبر نیست.');
        }

        $firstInvoice = ! in_array($request->status, [
            RepairRequest::STATUS_INVOICED,
            RepairRequest::STATUS_PAYMENT_REVIEW,
        ], true);

        $percent = $sharePercent !== null && $byAdmin
            ? $sharePercent
            : (float) ($request->share_percent ?: optional($request->technician)->labor_share_percent);

        $request->fill([
            'labor_amount' => $labor,
            'parts_amount' => $parts,
            'cost_description' => $description,
            'share_percent' => max(0, min(100, $percent)),
            'invoiced_at' => $firstInvoice ? now() : $request->invoiced_at,
        ]);
        if ($firstInvoice) {
            $request->status = RepairRequest::STATUS_INVOICED;
            $request->started_at = $request->started_at ?: now();
        }
        $this->applyShares($request);
        $request->save();

        if ($firstInvoice) {
            $this->notifier->invoiced($request);
        }

        return $request->fresh(['technician', 'customer']);
    }

    public function applyShares(RepairRequest $request): void
    {
        $labor = (int) $request->labor_amount;
        $parts = (int) $request->parts_amount;
        $percent = max(0, min(100, (float) $request->share_percent));
        $technicianShare = (int) round($labor * $percent / 100) + $parts;
        $total = $labor + $parts;

        $request->total_amount = $total;
        $request->technician_share = min($total, $technicianShare);
        $request->platform_share = max(0, $total - $request->technician_share);
    }

    public function submitReceipt(RepairRequest $request, string $content, string $ext, ?string $paymentRef): RepairRequest
    {
        if (! in_array($request->status, [RepairRequest::STATUS_INVOICED, RepairRequest::STATUS_PAYMENT_REVIEW], true)) {
            throw new RuntimeException('برای این درخواست امکان ارسال رسید نیست.');
        }

        $oldPath = $request->receipt_path;
        $path = ImageTools::saveFile(
            "/repair-requests/{$request->id}/receipt_".time().'.'.$ext,
            $content
        );

        $request->update([
            'status' => RepairRequest::STATUS_PAYMENT_REVIEW,
            'payment_method' => RepairRequest::METHOD_CARD_TO_CARD,
            'payment_ref' => $paymentRef,
            'receipt_path' => $path,
            'receipt_submitted_at' => now(),
            'receipt_reject_reason' => null,
        ]);

        if ($oldPath && $oldPath !== $path && Storage::exists('public/'.$oldPath)) {
            Storage::delete('public/'.$oldPath);
        }

        $this->notifier->receiptSubmitted($request);

        return $request->fresh(['technician', 'customer']);
    }

    public function rejectReceipt(RepairRequest $request, ?string $reason): RepairRequest
    {
        if ($request->status !== RepairRequest::STATUS_PAYMENT_REVIEW) {
            throw new RuntimeException('رسیدی برای بررسی وجود ندارد.');
        }
        $request->update([
            'status' => RepairRequest::STATUS_INVOICED,
            'receipt_reject_reason' => $reason ?: 'رسید تأیید نشد.',
        ]);
        $this->notifier->receiptRejected($request);

        return $request->fresh(['technician', 'customer']);
    }

    /**
     * تأیید پرداخت (رسید کارت به کارت یا ثبت دستی ادمین) و تکمیل کار.
     */
    public function confirmPayment(RepairRequest $request, string $method, ?string $ref = null): RepairRequest
    {
        if (! in_array($request->status, [RepairRequest::STATUS_INVOICED, RepairRequest::STATUS_PAYMENT_REVIEW], true)) {
            throw new RuntimeException('این درخواست منتظر پرداخت نیست.');
        }
        if ((int) $request->total_amount <= 0) {
            throw new RuntimeException('هزینهٔ این درخواست ثبت نشده است.');
        }

        $this->complete($request, $method, $ref ?: $request->payment_ref);

        return $request->fresh(['technician', 'customer']);
    }

    /**
     * از fulfill درگاه صدا زده می‌شود؛ پول گرفته شده، پس اینجا خطا پرتاب نمی‌شود.
     */
    public function fulfillOnlinePayment(GatewayPayment $payment, ?string $refId): void
    {
        /** @var RepairRequest|null $request */
        $request = RepairRequest::query()->where('id', $payment->item_id)->lockForUpdate()->first();
        if (! $request) {
            return;
        }

        $expectedRial = (int) $request->total_amount * 10;
        $payable = in_array($request->status, [RepairRequest::STATUS_INVOICED, RepairRequest::STATUS_PAYMENT_REVIEW], true);
        if (! $payable || $expectedRial !== (int) $payment->amount_rial) {
            $note = trim((string) $request->admin_note."\n".'پرداخت آنلاین #'.$payment->id.' به مبلغ '
                .number_format((int) floor($payment->amount_rial / 10)).' تومان دریافت شد ولی با وضعیت/مبلغ درخواست هم‌خوانی نداشت؛ بررسی شود.');
            $request->update(['admin_note' => mb_substr($note, 0, 2000)]);

            return;
        }

        $request->gateway_payment_id = $payment->id;
        $this->complete($request, RepairRequest::METHOD_ONLINE, $refId);
    }

    public function cancel(RepairRequest $request, ?string $reason): RepairRequest
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('این درخواست قابل لغو نیست.');
        }
        $request->update([
            'status' => RepairRequest::STATUS_CANCELED,
            'canceled_at' => now(),
            'cancel_reason' => $reason,
        ]);
        $this->notifier->canceled($request);

        return $request->fresh(['technician', 'customer']);
    }

    /**
     * @return array<string, mixed>
     */
    public function technicianBalance(RepairUser $technician): array
    {
        $earned = (int) RepairRequest::query()
            ->where('technician_id', $technician->id)
            ->where('status', RepairRequest::STATUS_COMPLETED)
            ->sum('technician_share');
        $jobs = (int) RepairRequest::query()
            ->where('technician_id', $technician->id)
            ->where('status', RepairRequest::STATUS_COMPLETED)
            ->count();
        $paid = (int) RepairPayout::query()->where('technician_id', $technician->id)->sum('amount');

        return [
            'completed_jobs' => $jobs,
            'earned' => $earned,
            'paid' => $paid,
            'balance' => $earned - $paid,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function balancesReport(): array
    {
        $earned = RepairRequest::query()
            ->where('status', RepairRequest::STATUS_COMPLETED)
            ->whereNotNull('technician_id')
            ->groupBy('technician_id')
            ->select('technician_id', DB::raw('SUM(technician_share) as earned'), DB::raw('SUM(total_amount) as revenue'), DB::raw('SUM(platform_share) as platform'), DB::raw('COUNT(*) as jobs'))
            ->get()
            ->keyBy('technician_id');
        $paid = RepairPayout::query()
            ->groupBy('technician_id')
            ->select('technician_id', DB::raw('SUM(amount) as paid'))
            ->pluck('paid', 'technician_id');

        return RepairUser::query()
            ->where('role', RepairUser::ROLE_TECHNICIAN)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(function (RepairUser $technician) use ($earned, $paid) {
                $row = $earned->get($technician->id);
                $earnedAmount = $row ? (int) $row->earned : 0;
                $paidAmount = (int) ($paid[$technician->id] ?? 0);

                return [
                    'technician' => $technician->toTechnicianArray(),
                    'completed_jobs' => $row ? (int) $row->jobs : 0,
                    'revenue' => $row ? (int) $row->revenue : 0,
                    'platform_share' => $row ? (int) $row->platform : 0,
                    'earned' => $earnedAmount,
                    'paid' => $paidAmount,
                    'balance' => $earnedAmount - $paidAmount,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function readReceiptUpload(?UploadedFile $file, ?string $base64): array
    {
        $ext = 'jpeg';
        $content = null;
        if ($file) {
            $origExt = strtolower((string) $file->getClientOriginalExtension());
            $ext = in_array($origExt, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true) ? $origExt : 'jpeg';
            $content = file_get_contents($file->getRealPath());
        } elseif (is_string($base64) && $base64 !== '') {
            $raw = $base64;
            if (strpos($raw, ',') !== false) {
                $header = strtolower(strstr($raw, ',', true) ?: '');
                $raw = substr($raw, strpos($raw, ',') + 1);
                if (strpos($header, 'png') !== false) {
                    $ext = 'png';
                } elseif (strpos($header, 'webp') !== false) {
                    $ext = 'webp';
                } elseif (strpos($header, 'pdf') !== false) {
                    $ext = 'pdf';
                }
            }
            $content = base64_decode($raw, true);
        }

        if ($content === false || $content === null || $content === '') {
            throw new RuntimeException('فایل رسید را ارسال کنید.');
        }
        if (strlen($content) > 5 * 1024 * 1024) {
            throw new RuntimeException('حجم رسید نباید بیشتر از ۵ مگابایت باشد.');
        }

        return [$content, $ext];
    }

    private function complete(RepairRequest $request, string $method, ?string $ref): void
    {
        $this->applyShares($request);
        $request->fill([
            'status' => RepairRequest::STATUS_COMPLETED,
            'payment_method' => $method,
            'payment_ref' => $ref,
            'paid_at' => now(),
            'completed_at' => now(),
            'receipt_reject_reason' => null,
        ]);
        $request->save();

        $this->notifier->completed($request->fresh(['technician']));
    }
}
