<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\GoogleSheetsException;
use App\Services\GoogleSheets\ShopGoogleSheetExportService;
use Illuminate\Console\Command;

class GoogleSheetsCheck extends Command
{
    protected $signature = 'google-sheets:check {spreadsheet? : لینک یا شناسهٔ یک شیت برای تست دسترسی}';

    protected $description = 'بررسی دسترسی سرور به Google Sheets API با Service Account';

    public function handle(GoogleSheetsClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('فایل کلید Service Account پیدا نشد یا نامعتبر است (GOOGLE_SHEETS_CREDENTIALS_PATH).');

            return 1;
        }
        $this->info('Service Account: '.$client->serviceAccountEmail());

        $input = (string) $this->argument('spreadsheet');
        if ($input === '') {
            $this->line('برای تست کامل، لینک یک شیت که با ایمیل بالا share شده را بدهید:');
            $this->line('php artisan google-sheets:check "https://docs.google.com/spreadsheets/d/..."');

            return 0;
        }

        $id = ShopGoogleSheetExportService::parseSpreadsheetId($input);
        if ($id === null) {
            $this->error('لینک شیت معتبر نیست.');

            return 1;
        }

        try {
            $meta = $client->getSpreadsheet($id);
        } catch (GoogleSheetsException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info('اتصال موفق. عنوان شیت: '.($meta['properties']['title'] ?? '-'));

        return 0;
    }
}
