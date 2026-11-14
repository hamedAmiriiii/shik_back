<?php

namespace App\Console\Commands;

use App\Models\ConsumableMaterial;
use App\Services\ConsumableToRawMoveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MoveConsumablesToRaw extends Command
{
    protected $signature = 'consumables:move-to-raw
                            {atelier_id : شناسه فروشگاه}
                            {--ids= : لیست آیدی‌ها با کاما، مثل 10,11,12}
                            {--eligible : همهٔ کالاهای واجد شرایط همین فروشگاه}
                            {--dry-run : فقط گزارش؛ هیچ انتقالی انجام نشود}
                            {--force : بدون تأیید تعاملی}';

    protected $description = 'انتقال گروهی کالاهای انبار مصرفی به انبار مواد اولیه با همان منطق move-to-raw';

    public function handle(ConsumableToRawMoveService $mover): int
    {
        $atelierId = (int) $this->argument('atelier_id');
        if ($atelierId <= 0) {
            $this->error('atelier_id نامعتبر است.');

            return self::FAILURE;
        }

        $idsOption = trim((string) $this->option('ids'));
        $eligible = (bool) $this->option('eligible');
        $dryRun = (bool) $this->option('dry-run');

        if ($idsOption === '' && ! $eligible) {
            $this->error('یکی از --ids یا --eligible را مشخص کنید.');

            return self::FAILURE;
        }
        if ($idsOption !== '' && $eligible) {
            $this->error('فقط یکی از --ids یا --eligible را بزنید.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('consumable_materials')) {
            $this->error('جدول consumable_materials آماده نیست.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('raw_materials') || ! Schema::hasTable('raw_material_lots')) {
            $this->error('جداول مواد اولیه آماده نیست.');

            return self::FAILURE;
        }

        $query = ConsumableMaterial::query()
            ->where('atelier_id', $atelierId)
            ->orderBy('id');

        if ($idsOption !== '') {
            $ids = collect(preg_split('/[,\s]+/', $idsOption, -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($v) => (int) $v)
                ->filter(fn ($v) => $v > 0)
                ->unique()
                ->values()
                ->all();
            if ($ids === []) {
                $this->error('هیچ آیدی معتبری در --ids نیست.');

                return self::FAILURE;
            }
            $query->whereIn('id', $ids);
        }

        $materials = $query->get();
        if ($materials->isEmpty()) {
            $this->warn('کالایی برای بررسی پیدا نشد.');

            return self::SUCCESS;
        }

        if ($idsOption !== '') {
            $found = $materials->pluck('id')->all();
            $missing = array_values(array_diff($ids, $found));
            if ($missing !== []) {
                $this->warn('این آیدی‌ها در این فروشگاه پیدا نشدند: '.implode(', ', $missing));
            }
        }

        $candidates = [];
        $skipped = [];
        foreach ($materials as $material) {
            $reason = $mover->blockReason($material);
            if ($reason) {
                $skipped[] = [
                    'id' => $material->id,
                    'name' => $material->name,
                    'reason' => $reason,
                ];
                continue;
            }
            $candidates[] = $material;
        }

        $this->info('فروشگاه #'.$atelierId);
        $this->info('بررسی‌شده: '.$materials->count());
        $this->info('واجد شرایط: '.count($candidates));
        $this->info('ردشده قبل از انتقال: '.count($skipped));

        if ($skipped !== []) {
            $this->newLine();
            $this->line('ردشده‌ها:');
            foreach ($skipped as $row) {
                $this->line('  #'.$row['id'].' '.$row['name'].' → '.$row['reason']);
            }
        }

        if ($candidates === []) {
            $this->warn('هیچ کالای واجد شرایطی برای انتقال نیست.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('حالت dry-run — این کالاها منتقل می‌شدند:');
            foreach ($candidates as $material) {
                $this->line('  #'.$material->id.' '.$material->name.' ('.$material->unitLabelFa().')');
            }

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $ok = $this->confirm(
                count($candidates).' کالا به انبار مواد اولیه منتقل شوند؟',
                false
            );
            if (! $ok) {
                $this->warn('لغو شد.');

                return self::SUCCESS;
            }
        }

        $moved = 0;
        $failed = [];
        $bar = $this->output->createProgressBar(count($candidates));
        $bar->start();

        foreach ($candidates as $material) {
            $id = $material->id;
            $name = $material->name;
            try {
                // مدل را تازه می‌گیریم تا بعد از حذف‌های قبلی stale نباشد
                $fresh = ConsumableMaterial::query()
                    ->where('atelier_id', $atelierId)
                    ->where('id', $id)
                    ->first();
                if (! $fresh) {
                    $failed[] = [
                        'id' => $id,
                        'name' => $name,
                        'reason' => 'کالا دیگر موجود نیست.',
                    ];
                    $bar->advance();
                    continue;
                }
                $raw = $mover->move($fresh);
                $moved++;
                $this->newLine();
                $this->line('  ✓ #'.$id.' «'.$name.'» → raw #'.$raw->id);
            } catch (RuntimeException $e) {
                $failed[] = [
                    'id' => $id,
                    'name' => $name,
                    'reason' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'id' => $id,
                    'name' => $name,
                    'reason' => $e->getMessage(),
                ];
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('منتقل‌شده: '.$moved);
        $this->info('ناموفق هنگام انتقال: '.count($failed));

        if ($failed !== []) {
            $this->newLine();
            $this->line('ناموفق‌ها:');
            foreach ($failed as $row) {
                $this->line('  #'.$row['id'].' '.$row['name'].' → '.$row['reason']);
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
