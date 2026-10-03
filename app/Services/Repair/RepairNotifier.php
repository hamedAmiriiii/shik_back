<?php

namespace App\Services\Repair;

use App\Models\RepairRequest;
use App\Models\RepairSetting;
use App\Models\RepairUser;
use App\Tools\SmsTools;
use Illuminate\Support\Facades\Log;
use Throwable;

class RepairNotifier
{
    public function requestCreated(RepairRequest $request): void
    {
        $phones = RepairUser::query()
            ->where('role', RepairUser::ROLE_ADMIN)
            ->where('is_active', true)
            ->pluck('phone')
            ->merge(config('repair.admin_phones', []))
            ->unique()
            ->values()
            ->all();

        $text = $this->brand().': درخواست تعمیر جدید #'.$request->id
            .($request->category ? ' ('.$request->category.')' : '')
            ."\n".$this->link('/repair/admin/requests/'.$request->id);
        foreach ($phones as $phone) {
            $this->send((string) $phone, $text);
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
                ."\n".$this->link('/repair/tech/requests/'.$request->id)
            );
        }

        $this->send(
            $request->contact_phone,
            $this->brand().': برای درخواست #'.$request->id.' تعمیرکار '
            .($technician ? $technician->name : '').' ارجاع شد و به‌زودی با شما تماس می‌گیرد.'
        );
    }

    public function invoiced(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': هزینهٔ درخواست #'.$request->id.' مبلغ '
            .number_format((int) $request->total_amount).' تومان است.'
            ."\nپرداخت: ".$this->link('/repair/requests/'.$request->id)
        );
    }

    public function receiptSubmitted(RepairRequest $request): void
    {
        $phones = RepairUser::query()
            ->where('role', RepairUser::ROLE_ADMIN)
            ->where('is_active', true)
            ->pluck('phone')
            ->merge(config('repair.admin_phones', []))
            ->unique()
            ->all();
        foreach ($phones as $phone) {
            $this->send(
                (string) $phone,
                $this->brand().': رسید کارت به کارت درخواست #'.$request->id.' ثبت شد.'
                ."\n".$this->link('/repair/admin/requests/'.$request->id)
            );
        }
    }

    public function receiptRejected(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': رسید پرداخت درخواست #'.$request->id.' تأیید نشد.'
            .($request->receipt_reject_reason ? ' علت: '.$request->receipt_reject_reason : '')
            ."\n".$this->link('/repair/requests/'.$request->id)
        );
    }

    public function completed(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': پرداخت درخواست #'.$request->id.' ثبت شد. از اعتماد شما سپاسگزاریم.'
        );

        $technician = $request->technician;
        if ($technician) {
            $this->send(
                $technician->phone,
                $this->brand().': درخواست #'.$request->id.' تسویه شد. سهم شما: '
                .number_format((int) $request->technician_share).' تومان'
            );
        }
    }

    public function canceled(RepairRequest $request): void
    {
        $this->send(
            $request->contact_phone,
            $this->brand().': درخواست #'.$request->id.' لغو شد.'
            .($request->cancel_reason ? ' '.$request->cancel_reason : '')
        );

        $technician = $request->technician;
        if ($technician) {
            $this->send($technician->phone, $this->brand().': درخواست #'.$request->id.' لغو شد.');
        }
    }

    public function technicianRegistered(RepairUser $technician): void
    {
        $text = $this->brand().': تعمیرکار جدید ثبت‌نام کرد: '.$technician->name.' ('.$technician->phone.')'
            ."\nبرای تأیید: ".$this->link('/repair/admin/technicians');
        foreach ($this->adminPhones() as $phone) {
            $this->send($phone, $text);
        }
    }

    public function technicianApproved(RepairUser $technician): void
    {
        $this->send(
            $technician->phone,
            $this->brand().': ثبت‌نام شما به‌عنوان تعمیرکار تأیید شد.'
            ."\nورود: ".$this->link('/repair/tech/login')
        );
    }

    public function technicianRejected(RepairUser $technician): void
    {
        $this->send(
            $technician->phone,
            $this->brand().': ثبت‌نام شما به‌عنوان تعمیرکار تأیید نشد.'
            .($technician->approval_note ? ' '.$technician->approval_note : '')
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

    public function send(?string $phone, string $text): void
    {
        if (! is_string($phone) || $phone === '') {
            return;
        }
        try {
            SmsTools::sendSms($phone, $text);
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
