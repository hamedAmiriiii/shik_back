<?php

namespace App\Services\Repair;

use App\Models\RepairRequest;
use App\Models\RepairSetting;
use App\Models\RepairSmsLog;
use App\Models\RepairUser;
use Illuminate\Support\Facades\Log;
use Throwable;

class RepairNotifier
{
    public function requestCreated(RepairRequest $request): void
    {
        $text = $this->brand().': درخواست تعمیر جدید #'.$request->id
            .($request->category ? ' ('.$request->category.')' : '')
            ."\n".$this->link('/repair/admin/requests/'.$request->id);
        foreach ($this->adminPhones() as $phone) {
            $this->send($phone, $text, RepairSmsLog::TYPE_REQUEST);
        }
    }

    public function assigned(RepairRequest $request): void
    {
        $technician = $request->technician;
        if ($technician) {
            $this->send(
                $technician->phone,
                $this->brand().': درخواست #'.$request->id.' به شما ارجاع شد.'
                ."\nآدرس: ".mb_substr((string) $request->address, 0, 120)
                ."\nتلفن مشتری: ".$request->contact_phone
                ."\n".$this->link('/repair/tech/requests/'.$request->id),
                RepairSmsLog::TYPE_ASSIGNED
            );
        }

        $this->send(
            $request->contact_phone,
            $this->brand().': برای درخواست #'.$request->id.' تعمیرکار '
            .($technician ? $technician->name : '').' ارجاع شد و به‌زودی با شما تماس می‌گیرد.',
            RepairSmsLog::TYPE_ASSIGNED
        );
    }

    public function invoiced(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': هزینهٔ درخواست #'.$request->id.' مبلغ '
            .number_format((int) $request->total_amount).' تومان است.'
            ."\nپرداخت: ".$this->link('/repair/requests/'.$request->id),
            RepairSmsLog::TYPE_INVOICED
        );
    }

    public function receiptSubmitted(RepairRequest $request): void
    {
        $text = $this->brand().': رسید کارت به کارت درخواست #'.$request->id.' ثبت شد.'
            ."\n".$this->link('/repair/admin/requests/'.$request->id);
        foreach ($this->adminPhones() as $phone) {
            $this->send($phone, $text, RepairSmsLog::TYPE_RECEIPT);
        }
    }

    public function receiptRejected(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': رسید پرداخت درخواست #'.$request->id.' تأیید نشد.'
            .($request->receipt_reject_reason ? ' علت: '.$request->receipt_reject_reason : '')
            ."\n".$this->link('/repair/requests/'.$request->id),
            RepairSmsLog::TYPE_RECEIPT
        );
    }

    public function completed(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': پرداخت درخواست #'.$request->id.' ثبت شد. از اعتماد شما سپاسگزاریم.',
            RepairSmsLog::TYPE_COMPLETED
        );

        $technician = $request->technician;
        if ($technician) {
            $this->send(
                $technician->phone,
                $this->brand().': درخواست #'.$request->id.' تسویه شد. سهم شما: '
                .number_format((int) $request->technician_share).' تومان',
                RepairSmsLog::TYPE_COMPLETED
            );
        }
    }

    public function canceled(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': درخواست #'.$request->id.' لغو شد.'
            .($request->cancel_reason ? ' '.$request->cancel_reason : ''),
            RepairSmsLog::TYPE_CANCELED
        );

        $technician = $request->technician;
        if ($technician) {
            $this->send(
                $technician->phone,
                $this->brand().': درخواست #'.$request->id.' لغو شد.',
                RepairSmsLog::TYPE_CANCELED
            );
        }
    }

    public function technicianRegistered(RepairUser $technician): void
    {
        $text = $this->brand().': تعمیرکار جدید ثبت‌نام کرد: '.$technician->name.' ('.$technician->phone.')'
            ."\nبرای تأیید: ".$this->link('/repair/admin/technicians');
        foreach ($this->adminPhones() as $phone) {
            $this->send($phone, $text, RepairSmsLog::TYPE_TECHNICIAN);
        }
    }

    public function technicianApproved(RepairUser $technician): void
    {
        $this->send(
            $technician->phone,
            $this->brand().': ثبت‌نام شما به‌عنوان تعمیرکار تأیید شد.'
            ."\nورود: ".$this->link('/repair/tech/login'),
            RepairSmsLog::TYPE_TECHNICIAN
        );
    }

    public function technicianRejected(RepairUser $technician): void
    {
        $this->send(
            $technician->phone,
            $this->brand().': ثبت‌نام شما به‌عنوان تعمیرکار تأیید نشد.'
            .($technician->approval_note ? ' '.$technician->approval_note : ''),
            RepairSmsLog::TYPE_TECHNICIAN
        );
    }

    /**
     * @return list<string>
     */
    private function adminPhones(): array
    {
        return RepairUser::query()
            ->where('role', RepairUser::ROLE_ADMIN)
            ->where('is_active', true)
            ->pluck('phone')
            ->merge(config('repair.admin_phones', []))
            ->map(fn ($p) => (string) $p)
            ->unique()
            ->values()
            ->all();
    }

    public function send(?string $phone, string $text, string $type = 'notify'): void
    {
        if (! is_string($phone) || $phone === '') {
            return;
        }
        try {
            app(RepairSms::class)->send($phone, $text, $type);
        } catch (Throwable $e) {
            Log::warning('repair sms failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    private function brand(): string
    {
        $brand = RepairSetting::value('brand_name');

        return $brand !== '' ? $brand : (string) config('repair.brand_name');
    }

    private function link(string $path): string
    {
        $base = (string) config('repair.frontend_url');

        return $base !== '' ? $base.$path : '';
    }
}
