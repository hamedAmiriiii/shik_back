<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AccountingPostingMap extends Model
{
    protected $fillable = [
        'atelier_id',
        'event_key',
        'account_id',
    ];

    protected $casts = [
        'atelier_id' => 'integer',
        'account_id' => 'integer',
    ];

    public static function tableReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = Schema::hasTable('accounting_posting_maps');
        }

        return $ready;
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }
}
