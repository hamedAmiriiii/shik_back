<?php

namespace App\Services\Moadian;

use App\Models\FormalInvoiceSellerProfile;
use App\Models\MoadianDocument;
use App\Models\MoadianSaleLink;
use App\Models\MoadianShopSetting;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * جمع‌آوری فروش‌ها و ساخت صورتحساب‌ها. فقط فروش‌ها را می‌خواند و در جدول‌های moadian_* می‌نویسد؛
 * هیچ کدی از فروش/برگشت/حذف را صدا نمی‌زند یا تغییر نمی‌دهد.
 *
 * تصمیم برای هر فروش (با مقایسهٔ تصویر فعلی با آخرین صورتحساب ثبت‌شده «head»):
 *  - فروش جدید ← اصلی
 *  - head هنوز ارسال نشده/ناموفق ← بازسازی همان سند (همان سریال و شماره مالیاتی)
 *  - head در انتظار نتیجه ← صبر
 *  - فروش حذف شد یا همهٔ اقلام برگشت خورد ← ابطالی
 *  - تغییر خریدار/نوع/شناسه کالا/نرخ یا قلم جدید ← ابطالی + اصلی جدید
 *  - فقط کاهش مقدار ← برگشت از فروش (بدنه = اقلام باقی‌مانده)
 *  - سایر تغییرات ← اصلاحی
 */
class MoadianDocumentService
{
    private ?MoadianSaleSnapshot $snapshotter = null;

    private ?FormalInvoiceSellerProfile $seller = null;

    public function __construct(private MoadianShopSetting $settings)
    {
    }

    /**
     * @return array{created:int, rebuilt:int, closed:int, failed:int}
     */
    public function collect(int $limit = 50): array
    {
        $stats = ['created' => 0, 'rebuilt' => 0, 'closed' => 0, 'failed' => 0];
        $atelierId = (int) $this->settings->atelier_id;

        $deletedLinkIds = DB::table('moadian_sale_links as l')
            ->where('l.atelier_id', $atelierId)
            ->where('l.closed', false)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('purchases as p')->whereColumn('p.id', 'l.purchase_id');
            })
            ->orderBy('l.id')
            ->limit($limit)
            ->pluck('l.id');

        $hasReturns = Schema::hasTable('purchase_item_returns');
        $changedLinkIds = DB::table('moadian_sale_links as l')
            ->join('purchases as p', 'p.id', '=', 'l.purchase_id')
            ->where('l.atelier_id', $atelierId)
            ->where('l.closed', false)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('moadian_documents as d')
                    ->whereColumn('d.id', 'l.head_document_id')
                    ->where('d.status', MoadianDocument::STATUS_SENT);
            })
            ->where(function ($q) use ($hasReturns) {
                $q->whereNull('l.checked_at')
                    ->orWhereColumn('p.updated_at', '>', 'l.checked_at')
                    ->orWhereExists(function ($s) {
                        $s->select(DB::raw(1))->from('purchased_products as pp')
                            ->whereColumn('pp.purchase_id', 'l.purchase_id')
                            ->whereColumn('pp.updated_at', '>', 'l.checked_at');
                    });
                if ($hasReturns) {
                    $q->orWhereExists(function ($s) {
                        $s->select(DB::raw(1))->from('purchase_item_returns as r')
                            ->whereColumn('r.purchase_id', 'l.purchase_id')
                            ->whereColumn('r.created_at', '>', 'l.checked_at');
                    });
                }
            })
            ->orderBy('l.id')
            ->limit($limit)
            ->pluck('l.id');

        $newPurchaseIds = DB::table('purchases as p')
            ->where('p.atelier_id', $atelierId)
            ->where('p.id', '>', (int) $this->settings->start_purchase_id)
            ->where('p.created_at', '<=', now()->subMinutes(2))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('moadian_sale_links as l')->whereColumn('l.purchase_id', 'p.id');
            })
            ->orderBy('p.id')
            ->limit($limit)
            ->pluck('p.id');

        $purchaseIds = MoadianSaleLink::query()
            ->whereIn('id', $deletedLinkIds->merge($changedLinkIds)->unique()->values())
            ->pluck('purchase_id')
            ->merge($newPurchaseIds);

        foreach ($purchaseIds as $purchaseId) {
            $this->safely(function () use ($purchaseId, &$stats) {
                $this->process((int) $purchaseId, $stats);
            }, $stats);
        }

        return $stats;
    }

    /**
     * بازسازی/صف‌گذاری دوبارهٔ یک سند ناموفق به درخواست کاربر.
     */
    public function retry(MoadianDocument $doc, ?int $userId = null): MoadianDocument
    {
        if ($doc->status !== MoadianDocument::STATUS_FAILED) {
            throw new \DomainException('فقط صورتحساب ناموفق قابل ارسال دوباره است.');
        }

        return DB::transaction(function () use ($doc, $userId) {
            $doc = MoadianDocument::query()->lockForUpdate()->findOrFail($doc->id);
            $link = $doc->purchase_id
                ? MoadianSaleLink::query()->where('purchase_id', $doc->purchase_id)->lockForUpdate()->first()
                : null;
            $isHead = $link && (int) $link->head_document_id === (int) $doc->id;

            if ($doc->subject === MoadianDocument::SUBJECT_CANCEL) {
                $errors = MoadianValidator::validate($doc->payloadArray());
                $doc->forceFill($this->queuedState($errors))->save();

                return $doc;
            }

            $purchase = $doc->purchase_id ? Purchase::query()->find($doc->purchase_id) : null;

            if ($doc->subject === MoadianDocument::SUBJECT_ORIGINAL && $purchase) {
                $snapshot = $this->snapshotter()->build($purchase);
                if (MoadianSaleSnapshot::isEmpty($snapshot)) {
                    $doc->forceFill(['status' => MoadianDocument::STATUS_DISCARDED])->save();
                    if ($isHead) {
                        $link->forceFill(['head_document_id' => null, 'closed' => true, 'checked_at' => now()])->save();
                    }

                    return $doc;
                }
                $this->rebuild($doc, $snapshot, $userId);
                if ($isHead) {
                    $link->forceFill(['fingerprint' => $snapshot['fingerprint']])->save();
                }

                return $doc->fresh();
            }

            // اصلاحی/برگشتی ناموفق: کنار گذاشته می‌شود تا جمع‌آورنده از روی آخرین سند ثبت‌شده دوباره تصمیم بگیرد.
            $doc->forceFill(['status' => MoadianDocument::STATUS_DISCARDED])->save();
            if ($isHead) {
                $link->forceFill([
                    'head_document_id' => $doc->reference_document_id,
                    'closed' => false,
                    'checked_at' => null,
                ])->save();
            }

            return $doc;
        });
    }

    private function safely(callable $fn, array &$stats): void
    {
        try {
            $fn();
        } catch (MoadianConfigException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $stats['failed']++;
            report($e);
        }
    }

    private function process(int $purchaseId, array &$stats): void
    {
        DB::transaction(function () use ($purchaseId, &$stats) {
            $checkedAt = now()->subSecond();

            $link = MoadianSaleLink::query()->where('purchase_id', $purchaseId)->lockForUpdate()->first();
            $purchase = Purchase::query()->find($purchaseId);
            if (! $link) {
                if (! $purchase) {
                    return;
                }
                $link = MoadianSaleLink::query()->create([
                    'atelier_id' => $this->settings->atelier_id,
                    'purchase_id' => $purchaseId,
                ]);
            }
            if ($link->closed) {
                return;
            }

            $snapshot = $purchase ? $this->snapshotter()->build($purchase) : null;
            $gone = $snapshot === null || MoadianSaleSnapshot::isEmpty($snapshot);
            $head = $link->head_document_id ? MoadianDocument::query()->find($link->head_document_id) : null;

            for ($guard = 0; $guard < 4 && $head; $guard++) {
                if ($head->status === MoadianDocument::STATUS_SENT) {
                    return;
                }
                if ($head->status === MoadianDocument::STATUS_SUCCESS) {
                    break;
                }
                if ($head->subject === MoadianDocument::SUBJECT_ORIGINAL
                    && in_array($head->status, [MoadianDocument::STATUS_QUEUED, MoadianDocument::STATUS_FAILED], true)) {
                    if ($gone) {
                        $head->forceFill(['status' => MoadianDocument::STATUS_DISCARDED])->save();
                        $link->forceFill(['head_document_id' => null, 'closed' => true, 'checked_at' => $checkedAt])->save();
                        $stats['closed']++;

                        return;
                    }
                    if ($snapshot['fingerprint'] !== $head->source_fingerprint) {
                        $this->rebuild($head, $snapshot, null);
                        $stats['rebuilt']++;
                    }
                    $link->forceFill(['fingerprint' => $snapshot['fingerprint'], 'checked_at' => $checkedAt])->save();

                    return;
                }
                if ($head->status !== MoadianDocument::STATUS_DISCARDED) {
                    $head->forceFill(['status' => MoadianDocument::STATUS_DISCARDED])->save();
                }
                $head = $head->reference_document_id ? MoadianDocument::query()->find($head->reference_document_id) : null;
                $link->head_document_id = $head?->id;
            }
            if ($head && $head->status !== MoadianDocument::STATUS_SUCCESS) {
                return;
            }

            if (! $head) {
                if ($gone) {
                    $link->forceFill(['head_document_id' => null, 'closed' => true, 'checked_at' => $checkedAt])->save();
                    $stats['closed']++;

                    return;
                }
                $doc = $this->createDocument(MoadianDocument::SUBJECT_ORIGINAL, $snapshot, null, $purchaseId);
                $link->forceFill(['head_document_id' => $doc->id, 'fingerprint' => $snapshot['fingerprint'], 'checked_at' => $checkedAt])->save();
                $stats['created']++;

                return;
            }

            if ($head->subject === MoadianDocument::SUBJECT_CANCEL) {
                $link->forceFill(['closed' => true, 'checked_at' => $checkedAt])->save();

                return;
            }

            if ($gone) {
                $doc = $this->createDocument(MoadianDocument::SUBJECT_CANCEL, null, $head, $purchaseId);
                $link->forceFill(['head_document_id' => $doc->id, 'closed' => true, 'checked_at' => $checkedAt])->save();
                $stats['created']++;

                return;
            }

            if ($snapshot['fingerprint'] === $head->source_fingerprint) {
                $link->forceFill(['fingerprint' => $snapshot['fingerprint'], 'checked_at' => $checkedAt])->save();

                return;
            }

            $change = $this->classifyChange($head, $snapshot);
            if ($change === 'structural') {
                $this->createDocument(MoadianDocument::SUBJECT_CANCEL, null, $head, $purchaseId);
                $doc = $this->createDocument(MoadianDocument::SUBJECT_ORIGINAL, $snapshot, null, $purchaseId);
                $stats['created'] += 2;
            } else {
                $subject = $change === 'decrease' ? MoadianDocument::SUBJECT_RETURN : MoadianDocument::SUBJECT_CORRECTION;
                $doc = $this->createDocument($subject, $snapshot, $head, $purchaseId);
                $stats['created']++;
            }
            $link->forceFill(['head_document_id' => $doc->id, 'fingerprint' => $snapshot['fingerprint'], 'checked_at' => $checkedAt])->save();
        });
    }

    /**
     * @return string structural | decrease | correction
     */
    private function classifyChange(MoadianDocument $head, array $snapshot): string
    {
        $headHeader = $head->payloadArray()['header'] ?? [];
        if ((int) $head->invoice_type !== (int) $snapshot['invoice_type']) {
            return 'structural';
        }
        if ((string) ($headHeader['tinb'] ?? '') !== (string) ($snapshot['buyer']['tinb'] ?? '')) {
            return 'structural';
        }

        $headItems = $head->items()->get()->keyBy('line_key');
        $anyDecrease = false;
        $anyIncrease = false;
        foreach ($snapshot['lines'] as $key => $line) {
            $item = $headItems->get($key);
            if (! $item) {
                return 'structural';
            }
            if ((string) $item->sstid !== (string) $line['sstid']
                || abs((float) $item->vra - (float) $line['vra']) > 0.001
                || abs((float) $item->odr - (float) $line['odr']) > 0.001) {
                return 'structural';
            }
            $diff = round($line['qty'] - (float) $item->am, 3);
            if ($diff < 0) {
                $anyDecrease = true;
            } elseif ($diff > 0) {
                $anyIncrease = true;
            }
        }
        foreach ($headItems->keys() as $key) {
            if (! isset($snapshot['lines'][$key])) {
                $anyDecrease = true;
            }
        }

        return $anyDecrease && ! $anyIncrease ? 'decrease' : 'correction';
    }

    private function createDocument(int $subject, ?array $snapshot, ?MoadianDocument $reference, ?int $purchaseId, string $triggeredBy = 'sale', ?int $userId = null): MoadianDocument
    {
        $seller = $this->seller();
        $memoryId = strtoupper(trim((string) $this->settings->memory_id));
        if ($memoryId === '') {
            throw new MoadianConfigException('شناسه یکتای حافظه مالیاتی وارد نشده است.');
        }

        $issuedAt = $subject === MoadianDocument::SUBJECT_ORIGINAL && $snapshot ? (int) $snapshot['issued_at'] : time();
        $serial = $this->nextSerial($memoryId);
        $taxid = MoadianTaxId::generate($memoryId, $issuedAt, $serial);
        $late = $this->isLate($issuedAt);

        $invoiceType = $snapshot ? (int) $snapshot['invoice_type'] : (int) ($reference->invoice_type ?? 2);
        $meta = [
            'taxid' => $taxid,
            'inno' => MoadianTaxId::inno($serial),
            'indatim' => $issuedAt * 1000,
            'indati2m' => $late ? time() * 1000 : $issuedAt * 1000,
            'insr' => $late,
            'subject' => $subject,
            'reference_taxid' => $reference?->taxid,
        ];

        $buyerSource = $snapshot ?? ['buyer' => $this->buyerFromPayload($reference)];
        $calc = ($subject !== MoadianDocument::SUBJECT_CANCEL && $snapshot)
            ? MoadianTaxCalculator::calculate($snapshot, (bool) $this->settings->price_includes_vat)
            : null;
        $payload = MoadianInvoiceBuilder::build($meta, $buyerSource, $calc, $seller, $invoiceType);
        $errors = MoadianValidator::validate($payload);
        $totals = $calc['totals'] ?? [];

        $doc = MoadianDocument::query()->create([
            'atelier_id' => $this->settings->atelier_id,
            'purchase_id' => $purchaseId,
            'subject' => $subject,
            'invoice_type' => $invoiceType,
            'pattern' => 1,
            'reference_document_id' => $reference?->id,
            'reference_taxid' => $reference?->taxid,
            'memory_id' => $memoryId,
            'serial' => $serial,
            'inno' => $meta['inno'],
            'taxid' => $taxid,
            'indatim' => $meta['indatim'],
            'indati2m' => $meta['indati2m'],
            'insr' => $late,
            'payload' => MoadianCrypto::json($payload),
            'source_fingerprint' => $snapshot['fingerprint'] ?? null,
            'tprdis' => $totals['tprdis'] ?? 0,
            'tdis' => $totals['tdis'] ?? 0,
            'tadis' => $totals['tadis'] ?? 0,
            'tvam' => $totals['tvam'] ?? 0,
            'todam' => $totals['todam'] ?? 0,
            'tbill' => $totals['tbill'] ?? 0,
            'setm' => (int) ($snapshot['setm'] ?? 1),
            'status' => $errors ? MoadianDocument::STATUS_FAILED : MoadianDocument::STATUS_QUEUED,
            'errors' => $errors ?: null,
            'warnings' => ($snapshot['warnings'] ?? []) ?: null,
            'triggered_by' => $triggeredBy,
            'user_id' => $userId,
        ]);

        $this->replaceItems($doc, $calc);

        return $doc;
    }

    private function rebuild(MoadianDocument $doc, array $snapshot, ?int $userId): void
    {
        $late = $this->isLate(intdiv((int) $doc->indatim, 1000));
        $meta = [
            'taxid' => $doc->taxid,
            'inno' => $doc->inno,
            'indatim' => (int) $doc->indatim,
            'indati2m' => $late ? time() * 1000 : (int) $doc->indatim,
            'insr' => $late,
            'subject' => (int) $doc->subject,
            'reference_taxid' => $doc->reference_taxid,
        ];
        $invoiceType = (int) $snapshot['invoice_type'];
        $calc = MoadianTaxCalculator::calculate($snapshot, (bool) $this->settings->price_includes_vat);
        $payload = MoadianInvoiceBuilder::build($meta, $snapshot, $calc, $this->seller(), $invoiceType);
        $errors = MoadianValidator::validate($payload);
        $totals = $calc['totals'];

        $doc->forceFill(array_merge([
            'invoice_type' => $invoiceType,
            'indati2m' => $meta['indati2m'],
            'insr' => $late,
            'payload' => MoadianCrypto::json($payload),
            'source_fingerprint' => $snapshot['fingerprint'],
            'tprdis' => $totals['tprdis'],
            'tdis' => $totals['tdis'],
            'tadis' => $totals['tadis'],
            'tvam' => $totals['tvam'],
            'todam' => $totals['todam'],
            'tbill' => $totals['tbill'],
            'setm' => (int) $snapshot['setm'],
            'warnings' => $snapshot['warnings'] ?: null,
            'user_id' => $userId ?? $doc->user_id,
        ], $this->queuedState($errors)))->save();

        $this->replaceItems($doc, $calc);
    }

    private function queuedState(array $errors): array
    {
        return [
            'status' => $errors ? MoadianDocument::STATUS_FAILED : MoadianDocument::STATUS_QUEUED,
            'errors' => $errors ?: null,
            'attempts' => 0,
            'next_attempt_at' => null,
            'uid' => null,
            'reference_number' => null,
        ];
    }

    private function replaceItems(MoadianDocument $doc, ?array $calc): void
    {
        $doc->items()->delete();
        foreach ($calc['items'] ?? [] as $item) {
            $doc->items()->create([
                'line_key' => $item['line_key'],
                'purchased_product_id' => $item['purchased_product_id'],
                'sstid' => $item['sstid'],
                'sstt' => $item['sstt'],
                'mu' => $item['mu'] !== '' ? $item['mu'] : null,
                'am' => $item['am'],
                'fee' => $item['fee'],
                'prdis' => $item['prdis'],
                'dis' => $item['dis'],
                'adis' => $item['adis'],
                'vra' => $item['vra'],
                'vam' => $item['vam'],
                'odr' => $item['odr'],
                'odam' => $item['odam'],
                'tsstam' => $item['tsstam'],
            ]);
        }
    }

    private function buyerFromPayload(?MoadianDocument $doc): ?array
    {
        if (! $doc) {
            return null;
        }
        $header = $doc->payloadArray()['header'] ?? [];
        if (empty($header['tinb'])) {
            return null;
        }

        return array_filter([
            'tob' => $header['tob'] ?? 1,
            'tinb' => $header['tinb'],
            'bid' => $header['bid'] ?? null,
            'bpc' => $header['bpc'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function isLate(int $issuedAtSeconds): bool
    {
        $days = $this->settings->late_threshold_days;
        if ($days === null || (int) $days <= 0) {
            return false;
        }

        return (time() - $issuedAtSeconds) > ((int) $days * 86400);
    }

    private function nextSerial(string $memoryId): int
    {
        return DB::transaction(function () use ($memoryId) {
            $atelierId = (int) $this->settings->atelier_id;
            $query = fn () => DB::table('moadian_serial_counters')
                ->where('atelier_id', $atelierId)
                ->where('memory_id', $memoryId)
                ->lockForUpdate()
                ->first();

            $row = $query();
            if (! $row) {
                $max = (int) MoadianDocument::query()
                    ->where('atelier_id', $atelierId)
                    ->where('memory_id', $memoryId)
                    ->max('serial');
                DB::table('moadian_serial_counters')->insertOrIgnore([
                    'atelier_id' => $atelierId,
                    'memory_id' => $memoryId,
                    'last_serial' => $max,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = $query();
            }

            $next = (int) $row->last_serial + 1;
            DB::table('moadian_serial_counters')
                ->where('id', $row->id)
                ->update(['last_serial' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    private function snapshotter(): MoadianSaleSnapshot
    {
        return $this->snapshotter ??= new MoadianSaleSnapshot($this->settings);
    }

    private function seller(): FormalInvoiceSellerProfile
    {
        if ($this->seller) {
            return $this->seller;
        }
        $seller = FormalInvoiceSellerProfile::query()->where('atelier_id', $this->settings->atelier_id)->first();
        if (! $seller || MoadianInvoiceBuilder::sellerTin($seller) === '') {
            throw new MoadianConfigException('کد اقتصادی یا شناسه ملی فروشنده در «مشخصات فاکتور رسمی» ثبت نشده است.');
        }

        return $this->seller = $seller;
    }
}
