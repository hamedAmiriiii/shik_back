<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Atelier;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\ShopAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class ShopBackupService
{
    public const CONFIRM_VALUES = ['1', 'true', 'RESTORE', 'restore', 'بازگردانی'];

    /** @var array<string, array<int, int>> */
    private $idMaps = [];

    /**
     * @return array<string, mixed>
     */
    public function summary(int $atelierId): array
    {
        $atelier = Atelier::findOrFail($atelierId);
        $idSets = $this->collectCurrentIdSets($atelierId);
        $tables = [];
        $files = 0;

        foreach (ShopBackupTables::definitions() as $def) {
            $tables[$def['name']] = count($idSets[$def['name']] ?? []);
            $files += $this->countFileRowsForIds($def, $idSets[$def['name']] ?? []);
        }

        $license = $this->normalizeStoredPath($this->atelierLicenseRaw($atelier));
        if ($license && $this->absolutePublicPath($license)) {
            $files++;
        }

        return [
            'atelier_id' => $atelierId,
            'atelier_code' => $atelier->code,
            'shop_name' => $atelier->name,
            'tables' => $tables,
            'files_count' => $files,
            'restore_confirm_values' => self::CONFIRM_VALUES,
            'notes' => [
                'بازگردانی داده‌های فعلی این فروشگاه را با فایل پشتیبان جایگزین می‌کند.',
                'حساب پرسنل، کد فروشگاه و دورهٔ اشتراک عوض نمی‌شود.',
                'سهمیه پیامک فروشگاه حفظ می‌شود.',
                'کدینگ، اسناد و آرتیکل حسابداری هم در پشتیبان است.',
            ],
        ];
    }

    /**
     * @return array{path: string, filename: string}
     */
    public function createZip(int $atelierId): array
    {
        $this->assertZipAvailable();

        $atelier = Atelier::findOrFail($atelierId);
        $tables = $this->collectTables($atelierId);
        $files = $this->collectAllFiles($atelier, $tables);

        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('ساخت پوشه موقت پشتیبان ممکن نشد.');
        }
        $this->purgeOldTempZips($dir);

        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $atelier->code) ?: 'shop';
        $filename = 'shop-backup-'.$safeCode.'-'.date('Ymd-His').'.zip';
        $zipPath = $dir.DIRECTORY_SEPARATOR.uniqid('shop-backup-', true).'.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ساخت فایل پشتیبان ممکن نشد.');
        }

        $licenseRaw = $this->atelierLicenseRaw($atelier);
        $manifest = [
            'format' => ShopBackupTables::FORMAT,
            'version' => ShopBackupTables::VERSION,
            'created_at' => now()->toIso8601String(),
            'atelier_id' => $atelierId,
            'atelier_code' => $atelier->code,
            'atelier' => [
                'name' => $atelier->getRawOriginal('name') ?? $atelier->name,
                'code' => $atelier->code,
                'address' => $atelier->getRawOriginal('address') ?? $atelier->address,
                'business_license' => $licenseRaw,
            ],
        ];

        $zip->addFromString('manifest.json', $this->jsonEncode($manifest));
        $zip->addFromString('data.json', $this->jsonEncode(['tables' => $tables]));

        foreach ($files as $relative => $absolute) {
            if (is_file($absolute)) {
                $zip->addFile($absolute, 'files/'.str_replace('\\', '/', $relative));
            }
        }

        $zip->close();

        if (! is_file($zipPath) || filesize($zipPath) < 32) {
            @unlink($zipPath);
            throw new RuntimeException('فایل پشتیبان خالی است.');
        }

        return ['path' => $zipPath, 'filename' => $filename];
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreFromZip(int $atelierId, string $zipPath): array
    {
        $this->assertZipAvailable();
        if (! is_file($zipPath)) {
            throw new RuntimeException('فایل پشتیبان یافت نشد.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('فایل پشتیبان قابل خواندن نیست. باید zip باشد.');
        }

        try {
            $manifest = $this->decodeJson($zip->getFromName('manifest.json'), 'manifest.json');
            if (($manifest['format'] ?? '') !== ShopBackupTables::FORMAT) {
                throw new RuntimeException('این فایل پشتیبان فروشگاه معتبر نیست.');
            }
            $version = (int) ($manifest['version'] ?? 0);
            if ($version < 1 || $version > ShopBackupTables::VERSION) {
                throw new RuntimeException('نسخه فایل پشتیبان پشتیبانی نمی‌شود.');
            }

            $data = $this->decodeJson($zip->getFromName('data.json'), 'data.json');
            $tables = $data['tables'] ?? [];
            if (! is_array($tables)) {
                throw new RuntimeException('ساختار data.json نامعتبر است.');
            }

            $atelier = Atelier::findOrFail($atelierId);
            $this->idMaps = [];
            $restored = [];

            try {
                $this->withoutForeignKeyChecks(function () use ($atelierId, $atelier, $manifest, $tables, $zip, &$restored) {
                    $this->deleteShopData($atelierId);
                    $restored = $this->insertTables($atelierId, $tables);
                    $this->updateForeignKeys($tables);
                    $this->rewriteActiveSourceKeys($atelierId);
                    $this->restoreFiles($atelierId, $tables, $zip);
                    $this->restoreAtelierMeta($atelier, $manifest, $zip);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                throw new RuntimeException('بازگردانی انجام نشد. فایل پشتیبان با ساختار فعلی فروشگاه سازگار نیست.');
            }

            ShopAccount::ensureDefaultsForAtelier($atelierId);
            ChartOfAccountsSeeder::ensureForAtelier($atelierId);
            Setting::ensureDefaultsForAtelier($atelierId);

            return [
                'atelier_id' => $atelierId,
                'atelier_code' => $atelier->fresh()->code,
                'restored_tables' => $restored,
            ];
        } finally {
            $zip->close();
        }
    }

    public static function isValidConfirm($value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        if (! is_string($value)) {
            return false;
        }

        return in_array(trim($value), self::CONFIRM_VALUES, true);
    }

    private function purgeOldTempZips(string $dir): void
    {
        $old = glob($dir.DIRECTORY_SEPARATOR.'shop-backup-*.zip') ?: [];
        foreach ($old as $path) {
            if (is_file($path) && filemtime($path) < time() - 3600) {
                @unlink($path);
            }
        }
    }

    private function assertZipAvailable(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('افزونه Zip در PHP فعال نیست.');
        }
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<int, int>  $ids
     */
    private function countFileRowsForIds(array $def, array $ids): int
    {
        $name = $def['name'];
        $cols = [];
        foreach ($def['files'] ?? [] as $col) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, $col)) {
                $cols[] = $col;
            }
        }
        if ($cols === [] || $ids === []) {
            return 0;
        }

        return (int) DB::table($name)->whereIn('id', $ids)->where(function ($q) use ($cols) {
            foreach ($cols as $i => $col) {
                $method = $i === 0 ? 'where' : 'orWhere';
                $q->{$method}(function ($q2) use ($col) {
                    $q2->whereNotNull($col)->where($col, '!=', '');
                });
            }
        })->count();
    }

    /**
     * @return array<int, int>
     */
    private function atelierScopedIds(int $atelierId, string $table): array
    {
        if ($table === '' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'atelier_id')) {
            return [];
        }

        return DB::table($table)->where('atelier_id', $atelierId)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $collected
     * @return array<int, int>
     */
    private function idsFromCollected(string $parent, array $collected): array
    {
        $ids = [];
        foreach ($collected[$parent] ?? [] as $row) {
            if (isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function collectTables(int $atelierId): array
    {
        $out = [];
        foreach (ShopBackupTables::definitions() as $def) {
            $name = $def['name'];
            $out[$name] = [];
            if (! Schema::hasTable($name)) {
                continue;
            }

            if (($def['scope'] ?? 'atelier') === 'atelier') {
                if (! Schema::hasColumn($name, 'atelier_id')) {
                    continue;
                }
                $rows = DB::table($name)->where('atelier_id', $atelierId)->orderBy('id')->get();
            } else {
                $parentIds = $this->idsFromCollected($def['parent'], $out);
                if ($parentIds === [] || ! Schema::hasColumn($name, $def['parent_key'])) {
                    continue;
                }
                $rows = DB::table($name)->whereIn($def['parent_key'], $parentIds)->orderBy('id')->get();
            }

            foreach ($rows as $row) {
                $out[$name][] = (array) $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tables
     * @return array<string, string>
     */
    private function collectAllFiles(Atelier $atelier, array $tables): array
    {
        $files = [];
        $license = $this->normalizeStoredPath($this->atelierLicenseRaw($atelier));
        if ($license) {
            $abs = $this->absolutePublicPath($license);
            if ($abs) {
                $files[$license] = $abs;
            }
        }

        foreach (ShopBackupTables::definitions() as $def) {
            foreach ($def['files'] ?? [] as $column) {
                foreach ($tables[$def['name']] ?? [] as $row) {
                    $relative = $this->normalizeStoredPath($row[$column] ?? null);
                    if (! $relative) {
                        continue;
                    }
                    $abs = $this->absolutePublicPath($relative);
                    if ($abs) {
                        $files[$relative] = $abs;
                    }
                }
            }
        }

        return $files;
    }

    private function atelierLicenseRaw(Atelier $atelier): ?string
    {
        $raw = $atelier->getRawOriginal('business_license');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function shouldSkipRestore(array $def): bool
    {
        return isset($def['restore']) && $def['restore'] === false;
    }

    private function deleteShopData(int $atelierId): void
    {
        $idSets = $this->collectCurrentIdSets($atelierId);
        $this->deleteCurrentFiles($idSets);

        $customerIds = $idSets['customers'] ?? [];
        if ($customerIds !== [] && Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', Customer::class)
                ->whereIn('tokenable_id', $customerIds)
                ->delete();
        }

        foreach (array_reverse(ShopBackupTables::definitions()) as $def) {
            if ($this->shouldSkipRestore($def) || ! Schema::hasTable($def['name'])) {
                continue;
            }
            $name = $def['name'];
            if (($def['scope'] ?? 'atelier') === 'atelier') {
                if (! Schema::hasColumn($name, 'atelier_id')) {
                    continue;
                }
                $query = DB::table($name)->where('atelier_id', $atelierId);
                $preserve = $def['preserve_keys'] ?? [];
                if ($preserve !== [] && Schema::hasColumn($name, 'key')) {
                    $query->whereNotIn('key', $preserve);
                }
                $query->delete();
                continue;
            }

            $ids = $idSets[$name] ?? [];
            if ($ids !== []) {
                DB::table($name)->whereIn('id', $ids)->delete();
            }
        }
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function collectCurrentIdSets(int $atelierId): array
    {
        $idSets = [];
        foreach (ShopBackupTables::definitions() as $def) {
            $name = $def['name'];
            $idSets[$name] = [];
            if (! Schema::hasTable($name)) {
                continue;
            }
            if (($def['scope'] ?? 'atelier') === 'atelier') {
                if (Schema::hasColumn($name, 'atelier_id')) {
                    $idSets[$name] = $this->atelierScopedIds($atelierId, $name);
                }
                continue;
            }
            $parentIds = $idSets[$def['parent']] ?? [];
            if ($parentIds === [] || ! Schema::hasColumn($name, $def['parent_key'])) {
                continue;
            }
            $idSets[$name] = DB::table($name)->whereIn($def['parent_key'], $parentIds)->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all();
        }

        return $idSets;
    }

    /**
     * @param  array<string, array<int, int>>  $idSets
     */
    private function deleteCurrentFiles(array $idSets): void
    {
        foreach (ShopBackupTables::definitions() as $def) {
            $fileCols = $def['files'] ?? [];
            $name = $def['name'];
            if ($fileCols === [] || ! Schema::hasTable($name)) {
                continue;
            }
            $ids = $idSets[$name] ?? [];
            if ($ids === []) {
                continue;
            }
            $existingCols = array_values(array_filter($fileCols, function ($col) use ($name) {
                return Schema::hasColumn($name, $col);
            }));
            if ($existingCols === []) {
                continue;
            }
            $rows = DB::table($name)->whereIn('id', $ids)->get($existingCols);
            foreach ($rows as $row) {
                foreach ($existingCols as $col) {
                    $this->deletePublicFile($row->{$col} ?? null);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tables
     * @return array<string, int>
     */
    private function insertTables(int $atelierId, array $tables): array
    {
        $counts = [];
        foreach (ShopBackupTables::definitions() as $def) {
            $name = $def['name'];
            if ($this->shouldSkipRestore($def) || ! Schema::hasTable($name)) {
                continue;
            }

            $this->idMaps[$name] = [];
            $inserted = 0;
            $columns = Schema::getColumnListing($name);
            $skip = $def['skip_columns'] ?? [];
            $preserve = $def['preserve_keys'] ?? [];

            foreach ($tables[$name] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ($preserve !== [] && isset($row['key']) && in_array($row['key'], $preserve, true)) {
                    continue;
                }

                $oldId = isset($row['id']) ? (int) $row['id'] : 0;
                unset($row['id']);
                foreach ($skip as $col) {
                    unset($row[$col]);
                }
                if (in_array('atelier_id', $columns, true)) {
                    $row['atelier_id'] = $atelierId;
                }
                $mapped = $this->mappedColumnsForRow($def, $row, $columns);
                foreach ($mapped as $col => $value) {
                    $row[$col] = $value;
                }
                $row = $this->filterColumns($row, $columns, $skip);
                if (isset($row['legacy_slot']) && $row['legacy_slot'] === '') {
                    $row['legacy_slot'] = null;
                }

                $newId = (int) DB::table($name)->insertGetId($row);
                if ($oldId > 0) {
                    $this->idMaps[$name][$oldId] = $newId;
                }
                $inserted++;
            }
            $counts[$name] = $inserted;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $tables
     */
    private function updateForeignKeys(array $tables): void
    {
        foreach (ShopBackupTables::definitions() as $def) {
            if ($this->shouldSkipRestore($def) || ! Schema::hasTable($def['name'])) {
                continue;
            }
            $fks = $def['fks'] ?? [];
            $typed = $def['typed_fks'] ?? [];
            $poly = $def['polymorphic_fks'] ?? [];
            if ($fks === [] && $typed === [] && $poly === []) {
                continue;
            }
            $name = $def['name'];
            $columns = Schema::getColumnListing($name);
            foreach ($tables[$name] ?? [] as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }
                $newId = $this->idMaps[$name][(int) $row['id']] ?? null;
                if (! $newId) {
                    continue;
                }
                $updates = $this->mappedColumnsForRow($def, $row, $columns);
                if ($updates !== []) {
                    DB::table($name)->where('id', $newId)->update($updates);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tables
     */
    private function restoreFiles(int $atelierId, array $tables, ZipArchive $zip): void
    {
        foreach (ShopBackupTables::definitions() as $def) {
            if ($this->shouldSkipRestore($def) || ! Schema::hasTable($def['name'])) {
                continue;
            }
            $fileCols = $def['files'] ?? [];
            if ($fileCols === []) {
                continue;
            }
            $name = $def['name'];
            $columns = Schema::getColumnListing($name);
            foreach ($tables[$name] ?? [] as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }
                $newId = $this->idMaps[$name][(int) $row['id']] ?? null;
                if (! $newId) {
                    continue;
                }
                $updates = [];
                foreach ($fileCols as $col) {
                    if (! in_array($col, $columns, true)) {
                        continue;
                    }
                    $updates[$col] = $this->copyZipFile(
                        $zip,
                        $atelierId,
                        $this->normalizeStoredPath($row[$col] ?? null)
                    );
                }
                if ($updates !== []) {
                    DB::table($name)->where('id', $newId)->update($updates);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function restoreAtelierMeta(Atelier $atelier, array $manifest, ZipArchive $zip): void
    {
        $meta = $manifest['atelier'] ?? [];
        $updates = [];
        if (! empty($meta['name']) && is_string($meta['name'])) {
            $updates['name'] = $meta['name'];
        }
        if (array_key_exists('address', $meta) && is_string($meta['address'])) {
            $updates['address'] = $meta['address'];
        }
        $this->deletePublicFile($this->atelierLicenseRaw($atelier));
        $newLicense = $this->copyZipFile(
            $zip,
            (int) $atelier->id,
            $this->normalizeStoredPath($meta['business_license'] ?? null)
        );
        if ($newLicense !== null) {
            $updates['business_license'] = $newLicense;
        }
        if ($updates !== []) {
            DB::table('ateliers')->where('id', $atelier->id)->update($updates);
        }
    }

    private function copyZipFile(ZipArchive $zip, int $atelierId, ?string $original): ?string
    {
        if (! $original) {
            return null;
        }
        $stream = $zip->getStream('files/'.str_replace('\\', '/', $original));
        if ($stream === false) {
            return null;
        }
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false || $contents === '') {
            return null;
        }

        $newRelative = 'restored/'.$atelierId.'/'.$original;
        Storage::put('public/'.$newRelative, $contents, 'public');

        return $newRelative;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $row  ردیف فایل پشتیبان (idهای قدیمی)
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    private function mappedColumnsForRow(array $def, array $row, array $columns): array
    {
        $updates = [];
        foreach ($def['fks'] ?? [] as $col => $refTable) {
            if (in_array($col, $columns, true) && array_key_exists($col, $row)) {
                $updates[$col] = $this->mappedId($refTable, $row[$col] ?? null);
            }
        }
        foreach ($def['typed_fks'] ?? [] as $col => $spec) {
            if (! in_array($col, $columns, true) || ! array_key_exists($col, $row)) {
                continue;
            }
            $type = (string) ($row[$spec['column'] ?? ''] ?? '');
            $table = $spec['map'][$type] ?? null;
            $updates[$col] = $table
                ? $this->mappedId($table, $row[$col] ?? null)
                : ($row[$col] ?? null);
        }
        foreach ($def['polymorphic_fks'] ?? [] as $col => $spec) {
            if (! in_array($col, $columns, true) || ! array_key_exists($col, $row)) {
                continue;
            }
            $type = (string) ($row[$spec['column'] ?? ''] ?? '');
            $table = $spec['map'][$type] ?? null;
            if (! $table) {
                continue;
            }
            $mapped = $this->mappedId($table, $row[$col] ?? null);
            if ($mapped !== null) {
                $updates[$col] = $mapped;
            }
        }

        return $updates;
    }

    private function rewriteActiveSourceKeys(int $atelierId): void
    {
        if (! Schema::hasTable('accounting_vouchers')
            || ! Schema::hasColumn('accounting_vouchers', 'active_source_key')
        ) {
            return;
        }

        $rows = DB::table('accounting_vouchers')
            ->where('atelier_id', $atelierId)
            ->get(['id', 'source_type', 'source_id', 'status', 'reverses_voucher_id']);

        foreach ($rows as $row) {
            $key = null;
            if ($row->status === AccountingVoucher::STATUS_POSTED && $row->reverses_voucher_id === null) {
                $key = AccountingVoucher::activeSourceKey((string) $row->source_type, (int) $row->source_id);
            }
            DB::table('accounting_vouchers')->where('id', $row->id)->update([
                'active_source_key' => $key,
            ]);
        }
    }

    private function mappedId(string $table, $oldId): ?int
    {
        if ($oldId === null || $oldId === '' || $oldId === false) {
            return null;
        }
        $oldId = (int) $oldId;

        return $oldId > 0 ? ($this->idMaps[$table][$oldId] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $skip
     * @return array<string, mixed>
     */
    private function filterColumns(array $row, array $columns, array $skip): array
    {
        $allowed = array_flip($columns);
        foreach ($skip as $col) {
            unset($allowed[$col]);
        }
        unset($allowed['id']);

        return array_intersect_key($row, $allowed);
    }

    private function normalizeStoredPath($path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $path = str_replace('\\', '/', trim($path));
        if (preg_match('#/storage/(.+)$#', $path, $m)) {
            $path = $m[1];
        }
        $path = ltrim($path, '/');
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, '..') !== false) {
            return null;
        }
        if (strpos($path, 'storage/') === 0) {
            $path = substr($path, 8);
        }
        if (strpos($path, 'public/') === 0) {
            $path = substr($path, 7);
        }

        return $path !== '' ? $path : null;
    }

    private function absolutePublicPath(string $relative): ?string
    {
        $relative = str_replace('\\', '/', ltrim($relative, '/'));
        foreach (['public/'.$relative, $relative] as $diskPath) {
            if (Storage::exists($diskPath)) {
                return Storage::path($diskPath);
            }
        }
        if (Storage::disk('public')->exists($relative)) {
            return Storage::disk('public')->path($relative);
        }

        return null;
    }

    private function deletePublicFile($path): void
    {
        $relative = $this->normalizeStoredPath($path);
        if (! $relative) {
            return;
        }
        if (Storage::exists('public/'.$relative)) {
            Storage::delete('public/'.$relative);
        }
        if (Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->delete($relative);
        }
    }

    /**
     * @param  callable(): mixed  $callback
     * @return mixed
     */
    private function withoutForeignKeyChecks(callable $callback)
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                return DB::transaction(function () use ($callback) {
                    return $callback();
                });
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return DB::transaction(function () use ($callback) {
            return $callback();
        });
    }

    /**
     * @param  mixed  $data
     */
    private function jsonEncode($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('تبدیل داده به JSON ممکن نشد.');
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson($raw, string $label): array
    {
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('فایل '.$label.' در پشتیبان نیست.');
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('فایل '.$label.' نامعتبر است.');
        }

        return $decoded;
    }
}
