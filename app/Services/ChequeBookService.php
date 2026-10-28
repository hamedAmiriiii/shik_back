<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\ChequeBook;
use App\Models\ChequeBookLeaf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ChequeBookService
{
    public const MAX_LEAVES = 500;

    public static function tablesReady(): bool
    {
        return Schema::hasTable('cheque_books') && Schema::hasTable('cheque_book_leaves');
    }

    /**
     * @param  array{title?:string|null,bank_name?:string|null,shop_account_id?:int|null,start_number:int|string,end_number:int|string,leaf_count?:int|null,note?:string|null}  $fields
     */
    public static function createBook(int $atelierId, array $fields): ChequeBook
    {
        if (! self::tablesReady()) {
            throw new RuntimeException('جداول دسته چک وجود ندارد. migration یا SQL را اجرا کنید.');
        }

        $start = self::parseLeafNumber($fields['start_number'] ?? null, 'شماره شروع');
        $end = self::parseLeafNumber($fields['end_number'] ?? null, 'شماره پایان');
        if ($end < $start) {
            throw new RuntimeException('شماره پایان باید بزرگ‌تر یا مساوی شماره شروع باشد.');
        }

        $leafCount = (int) ($end - $start + 1);
        if ($leafCount < 1) {
            throw new RuntimeException('تعداد برگه‌ها نامعتبر است.');
        }
        if ($leafCount > self::MAX_LEAVES) {
            throw new RuntimeException('حداکثر '.self::MAX_LEAVES.' برگه در هر دسته چک مجاز است.');
        }

        if (isset($fields['leaf_count']) && $fields['leaf_count'] !== null && $fields['leaf_count'] !== '') {
            $declared = (int) $fields['leaf_count'];
            if ($declared !== $leafCount) {
                throw new RuntimeException(
                    'تعداد برگه با بازه شماره هم‌خوانی ندارد. از '.$start.' تا '.$end.' برابر '.$leafCount.' برگه است.'
                );
            }
        }

        return DB::transaction(function () use ($atelierId, $fields, $start, $end, $leafCount) {
            $book = ChequeBook::create([
                'atelier_id' => $atelierId,
                'title' => isset($fields['title']) ? (trim((string) $fields['title']) ?: null) : null,
                'bank_name' => isset($fields['bank_name']) ? (trim((string) $fields['bank_name']) ?: null) : null,
                'shop_account_id' => ! empty($fields['shop_account_id']) ? (int) $fields['shop_account_id'] : null,
                'start_number' => $start,
                'end_number' => $end,
                'leaf_count' => $leafCount,
                'note' => isset($fields['note']) ? (trim((string) $fields['note']) ?: null) : null,
            ]);

            $rows = [];
            $now = now();
            for ($n = $start; $n <= $end; $n++) {
                $rows[] = [
                    'cheque_book_id' => $book->id,
                    'leaf_number' => $n,
                    'status' => ChequeBookLeaf::STATUS_UNUSED,
                    'cheque_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($rows) >= 100) {
                    ChequeBookLeaf::insert($rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                ChequeBookLeaf::insert($rows);
            }

            return $book->load(['shopAccount', 'leaves']);
        });
    }

    /**
     * برگه آزاد را قفل و مصرف می‌کند؛ شماره چک را برمی‌گرداند.
     *
     * @return array{leaf: ChequeBookLeaf, cheque_number: string, bank_name: ?string}
     */
    public static function consumeLeaf(int $atelierId, int $leafId): array
    {
        if (! self::tablesReady()) {
            throw new RuntimeException('جداول دسته چک وجود ندارد. migration یا SQL را اجرا کنید.');
        }

        /** @var ChequeBookLeaf|null $leaf */
        $leaf = ChequeBookLeaf::query()
            ->with('book')
            ->whereKey($leafId)
            ->lockForUpdate()
            ->first();

        if (! $leaf || ! $leaf->book || (int) $leaf->book->atelier_id !== $atelierId) {
            throw new RuntimeException('برگه دسته چک یافت نشد.');
        }
        if (! $leaf->isUnused()) {
            throw new RuntimeException('این برگه قبلاً استفاده شده یا باطل است.');
        }

        return [
            'leaf' => $leaf,
            'cheque_number' => (string) $leaf->leaf_number,
            'bank_name' => $leaf->book->bank_name ?: null,
        ];
    }

    public static function markLeafUsed(ChequeBookLeaf $leaf, Cheque $cheque): void
    {
        $leaf->status = ChequeBookLeaf::STATUS_USED;
        $leaf->cheque_id = $cheque->id;
        $leaf->save();
    }

    public static function releaseLeafForCheque(Cheque $cheque): void
    {
        if (! self::tablesReady() || ! Schema::hasColumn('cheques', 'cheque_book_leaf_id')) {
            return;
        }

        $leafId = $cheque->cheque_book_leaf_id ?? null;
        if (! $leafId) {
            return;
        }

        ChequeBookLeaf::where('id', $leafId)
            ->where('status', ChequeBookLeaf::STATUS_USED)
            ->update([
                'status' => ChequeBookLeaf::STATUS_UNUSED,
                'cheque_id' => null,
            ]);
    }

    /**
     * @param  mixed  $value
     */
    private static function parseLeafNumber($value, string $label): int
    {
        if ($value === null || $value === '') {
            throw new RuntimeException($label.' الزامی است.');
        }
        $raw = preg_replace('/[^\d]/', '', (string) $value);
        if ($raw === '' || ! ctype_digit($raw)) {
            throw new RuntimeException($label.' باید عدد صحیح باشد.');
        }
        $n = (int) $raw;
        if ($n < 1) {
            throw new RuntimeException($label.' باید بزرگ‌تر از صفر باشد.');
        }

        return $n;
    }
}
