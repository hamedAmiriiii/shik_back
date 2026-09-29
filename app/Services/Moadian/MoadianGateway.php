<?php

namespace App\Services\Moadian;

interface MoadianGateway
{
    /** اطلاعات حافظهٔ مالیاتی؛ برای «آزمایش اتصال» */
    public function fiscalInformation(): array;

    /**
     * @param  array<int, array{requestTraceId:string, payload:array}>  $invoices  payload = {header, body, payments}
     * @return array<string, array{reference_number:?string, error:?string}> کلید = requestTraceId
     */
    public function sendInvoices(array $invoices, array $documentIds = []): array;

    /**
     * @param  string[]  $referenceNumbers
     * @return array<string, array{status:string, errors:array, warnings:array}> کلید = referenceNumber
     */
    public function inquiryByReferenceNumbers(array $referenceNumbers, array $documentIds = []): array;
}
