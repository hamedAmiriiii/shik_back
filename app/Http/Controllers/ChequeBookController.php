<?php

namespace App\Http\Controllers;

use App\Models\ChequeBook;
use App\Models\ChequeBookLeaf;
use App\Services\ChequeBookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChequeBookController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        if (! ChequeBookService::tablesReady()) {
            return response()->json([
                'message' => 'جداول دسته چک وجود ندارد. migration یا SQL را اجرا کنید.',
                'data' => [],
            ], 503);
        }

        $query = ChequeBook::query()
            ->where('atelier_id', $atelierId)
            ->with('shopAccount')
            ->withCount([
                'leaves as unused_leaves_count' => function ($q) {
                    $q->where('status', ChequeBookLeaf::STATUS_UNUSED);
                },
                'leaves as used_leaves_count' => function ($q) {
                    $q->where('status', ChequeBookLeaf::STATUS_USED);
                },
            ])
            ->orderByDesc('id');

        if ($request->boolean('with_unused_leaves')) {
            $query->with(['unusedLeaves' => function ($q) {
                $q->limit(200);
            }]);
        }

        return response($query->get(), 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت دسته چک فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate([
            'title' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'shop_account_id' => 'nullable|integer|exists:shop_accounts,id',
            'start_number' => 'required',
            'end_number' => 'required',
            'leaf_count' => 'nullable|integer|min:1|max:'.ChequeBookService::MAX_LEAVES,
            'note' => 'nullable|string',
        ]);

        if (! empty($fields['shop_account_id'])) {
            $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id']);
            if ($accountError) {
                return response()->json(['message' => $accountError], 422);
            }
        }

        try {
            $book = ChequeBookService::createBook($atelierId, $fields);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $book->loadCount([
            'leaves as unused_leaves_count' => function ($q) {
                $q->where('status', ChequeBookLeaf::STATUS_UNUSED);
            },
            'leaves as used_leaves_count' => function ($q) {
                $q->where('status', ChequeBookLeaf::STATUS_USED);
            },
        ]);

        return response($book, 201);
    }

    public function show(Request $request, ChequeBook $chequeBook)
    {
        $this->assertModelBelongsToStaffAtelier($request, $chequeBook);

        $chequeBook->load(['shopAccount', 'leaves.cheque']);
        $chequeBook->loadCount([
            'leaves as unused_leaves_count' => function ($q) {
                $q->where('status', ChequeBookLeaf::STATUS_UNUSED);
            },
            'leaves as used_leaves_count' => function ($q) {
                $q->where('status', ChequeBookLeaf::STATUS_USED);
            },
        ]);

        return response($chequeBook, 200);
    }

    public function unusedLeaves(Request $request, ChequeBook $chequeBook)
    {
        $this->assertModelBelongsToStaffAtelier($request, $chequeBook);

        $leaves = $chequeBook->unusedLeaves()->limit(200)->get();

        return response($leaves, 200);
    }

    public function update(Request $request, ChequeBook $chequeBook)
    {
        $this->assertModelBelongsToStaffAtelier($request, $chequeBook);

        $fields = $request->validate([
            'title' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'shop_account_id' => 'nullable|integer|exists:shop_accounts,id',
            'note' => 'nullable|string',
        ]);

        if (array_key_exists('shop_account_id', $fields) && ! empty($fields['shop_account_id'])) {
            $accountError = $this->paymentAccountError((int) $chequeBook->atelier_id, $fields['shop_account_id']);
            if ($accountError) {
                return response()->json(['message' => $accountError], 422);
            }
        }

        if (array_key_exists('title', $fields)) {
            $fields['title'] = trim((string) ($fields['title'] ?? '')) ?: null;
        }
        if (array_key_exists('bank_name', $fields)) {
            $fields['bank_name'] = trim((string) ($fields['bank_name'] ?? '')) ?: null;
        }
        if (array_key_exists('note', $fields)) {
            $fields['note'] = trim((string) ($fields['note'] ?? '')) ?: null;
        }
        if (array_key_exists('shop_account_id', $fields) && empty($fields['shop_account_id'])) {
            $fields['shop_account_id'] = null;
        }

        $chequeBook->update($fields);

        return response($chequeBook->fresh(['shopAccount']), 200);
    }

    public function destroy(Request $request, ChequeBook $chequeBook)
    {
        $this->assertModelBelongsToStaffAtelier($request, $chequeBook);

        $used = $chequeBook->leaves()->where('status', ChequeBookLeaf::STATUS_USED)->exists();
        if ($used) {
            return response()->json([
                'message' => 'دسته چکی که برگه استفاده‌شده دارد قابل حذف نیست.',
            ], 422);
        }

        DB::transaction(function () use ($chequeBook) {
            $chequeBook->leaves()->delete();
            $chequeBook->delete();
        });

        return response(['message' => 'دسته چک حذف شد'], 200);
    }

    public function voidLeaf(Request $request, ChequeBook $chequeBook, ChequeBookLeaf $leaf)
    {
        $this->assertModelBelongsToStaffAtelier($request, $chequeBook);
        if ((int) $leaf->cheque_book_id !== (int) $chequeBook->id) {
            return response(['message' => 'یافت نشد'], 404);
        }
        if ($leaf->status === ChequeBookLeaf::STATUS_USED) {
            return response()->json(['message' => 'برگه استفاده‌شده قابل ابطال نیست.'], 422);
        }

        $leaf->update(['status' => ChequeBookLeaf::STATUS_VOID, 'cheque_id' => null]);

        return response($leaf->fresh(), 200);
    }
}
