<?php

namespace App\Services\GoogleSheets;

use App\Models\Atelier;
use App\Models\Setting;
use App\Services\ShopBackupService;
use App\Services\ShopBackupTables;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

/**
 * ارسال دادهٔ فروشگاه به گوگل شیت خود فروشگاه؛ هر جدول یک تب.
 * هر بار ارسال، محتوای تب‌ها را کامل با دادهٔ فعلی جایگزین می‌کند.
 */
class ShopGoogleSheetExportService
{
    public const KEY_SPREADSHEET_ID = 'google_sheet_spreadsheet_id';

    public const KEY_LAST_EXPORT_AT = 'google_sheet_last_export_at';

    public const KEY_LAST_EXPORT_ERROR = 'google_sheet_last_export_error';

    public const KEY_TABLES = 'google_sheet_tables';

    private const SUMMARY_TAB = 'خلاصه';

    /** سقف گوگل ۱۰ میلیون سلول در کل فایل است؛ کمی حاشیه برای تب‌های دیگر کاربر. */
    private const MAX_CELLS = 9500000;

    private const MAX_CELL_CHARS = 50000;

    private const ROWS_PER_RANGE = 2000;

    private const MAX_BATCH_BYTES = 2097152;

    private const HIDDEN_COLUMNS = [
        'password',
        'remember_token',
        'api_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @var GoogleSheetsClient */
    private $client;

    /** @var ShopBackupService */
    private $backups;

    /** @var ShopGoogleOAuth */
    private $oauth;

    public function __construct(GoogleSheetsClient $client, ShopBackupService $backups, ShopGoogleOAuth $oauth)
    {
        $this->client = $client;
        $this->backups = $backups;
        $this->oauth = $oauth;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $atelierId): array
    {
        $spreadsheetId = $this->setting($atelierId, self::KEY_SPREADSHEET_ID);
        $oauthEnabled = $this->oauth->isEnabled();
        $serviceAccount = $this->client->isConfigured();

        return [
            'configured' => $oauthEnabled || $serviceAccount,
            'oauth_enabled' => $oauthEnabled,
            'google_connected' => $oauthEnabled && $this->oauth->isConnected($atelierId),
            'google_email' => $this->oauth->connectedEmail($atelierId),
            'service_account_enabled' => $serviceAccount,
            'service_account_email' => $this->client->serviceAccountEmail(),
            'spreadsheet_id' => $spreadsheetId,
            'spreadsheet_url' => $spreadsheetId ? $this->spreadsheetUrl($spreadsheetId) : null,
            'last_export_at' => $this->setting($atelierId, self::KEY_LAST_EXPORT_AT),
            'last_export_error' => $this->setting($atelierId, self::KEY_LAST_EXPORT_ERROR),
            'tables' => GoogleSheetTableCatalog::catalog(),
            'selected_tables' => $this->selectedTables($atelierId),
        ];
    }

    /**
     * @return list<string>
     */
    public function selectedTables(int $atelierId): array
    {
        $available = GoogleSheetTableCatalog::names();
        $stored = json_decode((string) $this->setting($atelierId, self::KEY_TABLES), true);
        $selected = is_array($stored) ? $stored : GoogleSheetTableCatalog::DEFAULT_TABLES;

        return array_values(array_intersect($available, array_map('strval', $selected)));
    }

    /**
     * @param  array<int, mixed>  $tables
     * @return list<string>
     */
    public function setSelectedTables(int $atelierId, array $tables): array
    {
        $selected = array_values(array_intersect(GoogleSheetTableCatalog::names(), array_map('strval', $tables)));
        if ($selected === []) {
            throw new GoogleSheetsException('حداقل یک جدول را انتخاب کنید.');
        }
        $this->putSetting($atelierId, self::KEY_TABLES, (string) json_encode($selected));

        return $selected;
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(int $atelierId, string $urlOrId): array
    {
        $spreadsheetId = self::parseSpreadsheetId($urlOrId);
        if ($spreadsheetId === null) {
            throw new GoogleSheetsException('لینک گوگل شیت معتبر نیست.');
        }

        $meta = $this->client->getSpreadsheet($spreadsheetId);

        $this->putSetting($atelierId, self::KEY_SPREADSHEET_ID, $spreadsheetId);
        $this->putSetting($atelierId, self::KEY_LAST_EXPORT_ERROR, '');

        return array_merge($this->status($atelierId), [
            'spreadsheet_title' => $meta['properties']['title'] ?? null,
        ]);
    }

    public function disconnect(int $atelierId): void
    {
        if ($this->oauth->isConnected($atelierId)) {
            $this->oauth->disconnect($atelierId);
        }
        $this->putSetting($atelierId, self::KEY_SPREADSHEET_ID, '');
        $this->putSetting($atelierId, self::KEY_LAST_EXPORT_ERROR, '');
    }

    /**
     * @return array<string, mixed>
     */
    public function export(int $atelierId): array
    {
        $usesShopAccount = $this->oauth->isEnabled() && $this->oauth->isConnected($atelierId);
        $client = $usesShopAccount ? $this->client->forShopAccount($this->oauth, $atelierId) : $this->client;
        $spreadsheetId = $this->setting($atelierId, self::KEY_SPREADSHEET_ID);
        if (! $spreadsheetId && ! $usesShopAccount) {
            throw new GoogleSheetsException('ابتدا با حساب گوگل فروشگاه وارد شوید.');
        }

        $lock = null;
        $store = Cache::getStore();
        if ($store instanceof LockProvider) {
            $lock = $store->lock('google_sheet_export:'.$atelierId, 900);
            if (! $lock->get()) {
                throw new GoogleSheetsException('ارسال قبلی به گوگل شیت هنوز در حال انجام است.');
            }
        }

        try {
            $sheets = $this->buildSheets($atelierId);
            $this->assertWithinCellLimit($sheets);
            if ($usesShopAccount) {
                $spreadsheetId = $this->ensureShopSpreadsheet($client, $atelierId, $spreadsheetId);
            }
            $this->prepareTabs($client, $spreadsheetId, $sheets);
            $client->batchClearValues($spreadsheetId, array_map(function ($title) {
                return $this->quoteTitle($title);
            }, array_keys($sheets)));
            $this->writeValues($client, $spreadsheetId, $sheets);
        } catch (GoogleSheetsException $e) {
            $this->putSetting($atelierId, self::KEY_LAST_EXPORT_ERROR, $e->getMessage());
            throw $e;
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }

        $this->putSetting($atelierId, self::KEY_LAST_EXPORT_AT, now()->toIso8601String());
        $this->putSetting($atelierId, self::KEY_LAST_EXPORT_ERROR, '');

        $rows = 0;
        foreach ($sheets as $title => $values) {
            if ($title !== self::SUMMARY_TAB) {
                $rows += max(0, count($values) - 1);
            }
        }

        return [
            'spreadsheet_url' => $this->spreadsheetUrl($spreadsheetId),
            'tables' => count($sheets) - 1,
            'rows' => $rows,
            'last_export_at' => $this->setting($atelierId, self::KEY_LAST_EXPORT_AT),
        ];
    }

    /**
     * شیت فروشگاه در درایو خودش؛ اگر نبود یا کاربر پاکش کرده بود، تازه ساخته می‌شود.
     */
    private function ensureShopSpreadsheet(GoogleSheetsClient $client, int $atelierId, ?string $spreadsheetId): string
    {
        if ($spreadsheetId) {
            try {
                $client->getSpreadsheet($spreadsheetId);

                return $spreadsheetId;
            } catch (GoogleSheetsException $e) {
                if ($e->getCode() !== 404 && $e->getCode() !== 403) {
                    throw $e;
                }
            }
        }

        $atelier = Atelier::find($atelierId);
        $title = 'وبینو — '.trim((string) ($atelier->name ?? ('فروشگاه '.$atelierId)));
        $spreadsheetId = $client->createSpreadsheet($title);
        $this->putSetting($atelierId, self::KEY_SPREADSHEET_ID, $spreadsheetId);

        return $spreadsheetId;
    }

    public static function parseSpreadsheetId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]{20,})#', $input, $m)) {
            return $m[1];
        }
        if (preg_match('#^[A-Za-z0-9_-]{20,}$#', $input)) {
            return $input;
        }

        return null;
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>  عنوان تب ← ردیف‌ها (ردیف اول سرستون)
     */
    private function buildSheets(int $atelierId): array
    {
        $atelier = Atelier::findOrFail($atelierId);
        $tables = $this->backups->collectTables($atelierId);

        $sheets = [self::SUMMARY_TAB => []];
        $counts = [];
        $selected = array_flip($this->selectedTables($atelierId));
        foreach (ShopBackupTables::definitions() as $def) {
            $name = $def['name'];
            if (! isset($selected[$name]) || ! Schema::hasTable($name)) {
                continue;
            }
            $title = GoogleSheetTableCatalog::label($name);
            $columns = array_values(array_diff(Schema::getColumnListing($name), self::HIDDEN_COLUMNS));
            $values = [$columns];
            foreach ($tables[$name] ?? [] as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = $this->cell($row[$column] ?? null);
                }
                $values[] = $line;
            }
            $sheets[$title] = $values;
            $counts[$title] = count($values) - 1;
        }

        $summary = [
            ['فروشگاه', (string) $atelier->name],
            ['کد فروشگاه', (string) $atelier->code],
            ['زمان ارسال', Jalalian::fromCarbon(now()->setTimezone('Asia/Tehran'))->format('Y/m/d H:i')],
            [''],
            ['جدول', 'تعداد ردیف'],
        ];
        foreach ($counts as $name => $count) {
            $summary[] = [$name, $count];
        }
        $sheets[self::SUMMARY_TAB] = $summary;

        return $sheets;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function cell($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_array($value) || is_object($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = (string) $value;
        // صفر ابتدای موبایل و ارقام کارت/شبا نباید با تبدیل به عدد از بین برود
        if (preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }
        if (mb_strlen($value) > self::MAX_CELL_CHARS) {
            $value = mb_substr($value, 0, self::MAX_CELL_CHARS);
        }

        return $value;
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private function assertWithinCellLimit(array $sheets): void
    {
        $cells = 0;
        foreach ($sheets as $values) {
            $cells += $this->rowCount($values) * $this->columnCount($values);
        }
        if ($cells > self::MAX_CELLS) {
            throw new GoogleSheetsException('حجم دادهٔ فروشگاه از سقف ۱۰ میلیون سلول گوگل شیت بیشتر است.');
        }
    }

    /**
     * تب‌های لازم را می‌سازد و اندازهٔ هر تب را دقیقاً هم‌اندازهٔ داده می‌کند.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private function prepareTabs(GoogleSheetsClient $client, string $spreadsheetId, array $sheets): void
    {
        $meta = $client->getSpreadsheet($spreadsheetId);
        $existing = [];
        foreach ($meta['sheets'] ?? [] as $sheet) {
            $props = $sheet['properties'] ?? [];
            if (isset($props['title'], $props['sheetId'])) {
                $grid = $props['gridProperties'] ?? [];
                $existing[$props['title']] = [
                    'id' => (int) $props['sheetId'],
                    'cells' => (int) ($grid['rowCount'] ?? 0) * (int) ($grid['columnCount'] ?? 0),
                ];
            }
        }

        $add = [];
        foreach (array_keys($sheets) as $title) {
            if (! isset($existing[$title])) {
                $add[] = ['addSheet' => ['properties' => ['title' => $title]]];
            }
        }
        if ($add !== []) {
            $result = $client->batchUpdate($spreadsheetId, $add);
            foreach ($result['replies'] ?? [] as $reply) {
                $props = $reply['addSheet']['properties'] ?? null;
                if (is_array($props) && isset($props['title'], $props['sheetId'])) {
                    $grid = $props['gridProperties'] ?? [];
                    $existing[$props['title']] = [
                        'id' => (int) $props['sheetId'],
                        'cells' => (int) ($grid['rowCount'] ?? 0) * (int) ($grid['columnCount'] ?? 0),
                    ];
                }
            }
        }

        $resize = [];

        // تب جداولی که دیگر انتخاب نشده‌اند (با نام قدیمی انگلیسی یا عنوان فارسی) حذف شود
        $known = [];
        foreach (GoogleSheetTableCatalog::names() as $name) {
            $known[$name] = true;
            $known[GoogleSheetTableCatalog::label($name)] = true;
        }
        foreach ($existing as $title => $info) {
            if (isset($known[$title]) && ! isset($sheets[$title])) {
                $resize[] = [
                    'delta' => -$info['cells'],
                    'request' => ['deleteSheet' => ['sheetId' => $info['id']]],
                ];
            }
        }

        foreach ($sheets as $title => $values) {
            if (! isset($existing[$title])) {
                throw new GoogleSheetsException('ساخت تب «'.$title.'» در گوگل شیت ممکن نشد.');
            }
            $rows = $this->rowCount($values);
            $columns = $this->columnCount($values);
            $isSummary = $title === self::SUMMARY_TAB;
            $properties = [
                'sheetId' => $existing[$title]['id'],
                'gridProperties' => [
                    'rowCount' => $rows,
                    'columnCount' => $columns,
                    'frozenRowCount' => $isSummary ? 0 : 1,
                ],
            ];
            $fields = 'gridProperties(rowCount,columnCount,frozenRowCount)';
            if ($isSummary) {
                $properties['index'] = 0;
                $fields = 'index,'.$fields;
            }
            $resize[] = [
                'delta' => $rows * $columns - $existing[$title]['cells'],
                'request' => ['updateSheetProperties' => ['properties' => $properties, 'fields' => $fields]],
            ];
        }

        // کوچک‌شدن‌ها اول، تا سقف سلول فایل وسط کار رد نشود
        usort($resize, function ($a, $b) {
            return $a['delta'] <=> $b['delta'];
        });

        $client->batchUpdate($spreadsheetId, array_map(function ($item) {
            return $item['request'];
        }, $resize));
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private function writeValues(GoogleSheetsClient $client, string $spreadsheetId, array $sheets): void
    {
        $batch = [];
        $batchBytes = 0;

        foreach ($sheets as $title => $values) {
            if ($values === []) {
                continue;
            }
            $startRow = 1;
            foreach (array_chunk($values, self::ROWS_PER_RANGE) as $chunk) {
                $bytes = strlen((string) json_encode($chunk, JSON_UNESCAPED_UNICODE));
                if ($batch !== [] && $batchBytes + $bytes > self::MAX_BATCH_BYTES) {
                    $client->batchUpdateValues($spreadsheetId, $batch);
                    $batch = [];
                    $batchBytes = 0;
                }
                $batch[] = [
                    'range' => $this->quoteTitle($title).'!A'.$startRow,
                    'values' => $chunk,
                ];
                $batchBytes += $bytes;
                $startRow += count($chunk);
            }
        }

        if ($batch !== []) {
            $client->batchUpdateValues($spreadsheetId, $batch);
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $values
     */
    private function rowCount(array $values): int
    {
        // دست‌کم دو ردیف تا ثابت‌کردن ردیف سرستون خطا ندهد
        return max(2, count($values));
    }

    /**
     * @param  array<int, array<int, mixed>>  $values
     */
    private function columnCount(array $values): int
    {
        $max = 1;
        foreach ($values as $row) {
            $max = max($max, count($row));
        }

        return $max;
    }

    private function quoteTitle(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'";
    }

    private function spreadsheetUrl(string $spreadsheetId): string
    {
        return 'https://docs.google.com/spreadsheets/d/'.$spreadsheetId.'/edit';
    }

    private function setting(int $atelierId, string $key): ?string
    {
        $value = Setting::query()
            ->where('atelier_id', $atelierId)
            ->where('key', $key)
            ->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function putSetting(int $atelierId, string $key, string $value): void
    {
        $previous = Setting::contextAtelierId();
        Setting::setContextAtelierId($atelierId);
        try {
            Setting::set($key, $value);
        } finally {
            Setting::setContextAtelierId($previous);
        }
    }
}
