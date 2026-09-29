<?php

namespace App\Services\Moadian;

use App\Models\FormalInvoiceSellerProfile;
use App\Models\MoadianDocument;

/**
 * ساخت JSON صورتحساب (header / body / payments) طبق دستورالعمل RC_IITP.IS_V7.9، الگوی فروش (inp=1).
 */
class MoadianInvoiceBuilder
{
    /**
     * @param  array{taxid:string, inno:string, indatim:int, indati2m:?int, insr:bool, subject:int, reference_taxid:?string}  $meta
     * @param  array|null  $snapshot  برای ابطالی null
     * @param  array|null  $calc  خروجی MoadianTaxCalculator
     */
    public static function build(array $meta, ?array $snapshot, ?array $calc, FormalInvoiceSellerProfile $seller, int $invoiceType): array
    {
        $header = [
            'taxid' => $meta['taxid'],
            'indatim' => $meta['indatim'],
            'indati2m' => $meta['indati2m'] ?? $meta['indatim'],
            'inty' => $invoiceType,
            'inno' => $meta['inno'],
            'inp' => 1,
            'ins' => $meta['subject'],
            'tins' => self::sellerTin($seller),
        ];
        if (! empty($meta['insr'])) {
            $header['insr'] = 1;
        }
        if ($meta['subject'] !== MoadianDocument::SUBJECT_ORIGINAL && ! empty($meta['reference_taxid'])) {
            $header['irtaxid'] = $meta['reference_taxid'];
        }

        if ($invoiceType === 1 && $snapshot && ! empty($snapshot['buyer'])) {
            $buyer = $snapshot['buyer'];
            $header['tob'] = (int) $buyer['tob'];
            $header['tinb'] = (string) $buyer['tinb'];
            if (! empty($buyer['bid'])) {
                $header['bid'] = (string) $buyer['bid'];
            }
            if (! empty($buyer['bpc'])) {
                $header['bpc'] = (string) $buyer['bpc'];
            }
        }

        if ($meta['subject'] === MoadianDocument::SUBJECT_CANCEL || $calc === null) {
            return ['header' => $header, 'body' => [], 'payments' => []];
        }

        $totals = $calc['totals'];
        $header += [
            'tprdis' => $totals['tprdis'],
            'tdis' => $totals['tdis'],
            'tadis' => $totals['tadis'],
            'tvam' => $totals['tvam'],
            'todam' => $totals['todam'],
            'tbill' => $totals['tbill'],
            'setm' => (int) $snapshot['setm'],
        ];

        if ((int) $snapshot['setm'] === MoadianSaleSnapshot::SETM_MIXED) {
            $cash = min($totals['tbill'], (int) floor(((float) $snapshot['immediate_paid_toman']) * 10));
            $header['cap'] = $cash;
            $header['insp'] = $totals['tbill'] - $cash;
        }

        $body = [];
        foreach ($calc['items'] as $item) {
            $row = [
                'sstid' => $item['sstid'],
                'sstt' => $item['sstt'],
                'am' => self::number($item['am']),
            ];
            if ($item['mu'] !== '') {
                $row['mu'] = $item['mu'];
            }
            $row += [
                'fee' => $item['fee'],
                'prdis' => $item['prdis'],
                'dis' => $item['dis'],
                'adis' => $item['adis'],
                'vra' => self::number($item['vra']),
                'vam' => $item['vam'],
            ];
            if ($item['odr'] > 0) {
                if ($item['odt'] !== '') {
                    $row['odt'] = $item['odt'];
                }
                $row['odr'] = self::number($item['odr']);
                $row['odam'] = $item['odam'];
            }
            $row['tsstam'] = $item['tsstam'];
            $body[] = $row;
        }

        return ['header' => $header, 'body' => $body, 'payments' => []];
    }

    public static function sellerTin(FormalInvoiceSellerProfile $seller): string
    {
        $economic = preg_replace('/\D+/', '', (string) $seller->economic_code) ?? '';
        if ($economic !== '') {
            return $economic;
        }

        return preg_replace('/\D+/', '', (string) $seller->national_id) ?? '';
    }

    /** عدد صحیح بدون «.0» و اعشاری بدون صفرهای اضافه */
    private static function number(float $value)
    {
        if (abs($value - round($value)) < 0.0000001) {
            return (int) round($value);
        }

        return (float) rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
