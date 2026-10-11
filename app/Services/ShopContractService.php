<?php

namespace App\Services;

use App\Models\Atelier;
use App\Models\ShopContractAcceptance;
use App\Models\User;
use App\Support\ProjectType;
use Illuminate\Support\Facades\Schema;

/**
 * قرارداد استفاده از وبینو — صاحب فروشگاه باید قبل از استفاده از پنل با کد پیامکی تأیید کند.
 * برای الزام دوباره (تغییر متن قرارداد) فقط VERSION را افزایش دهید و ثابت هم‌نام در فرانت را همسان کنید.
 */
class ShopContractService
{
    public const VERSION = '1';

    public static function tableReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = Schema::hasTable('shop_contract_acceptances');
        }

        return $ready;
    }

    public static function isAccepted(int $atelierId): bool
    {
        if (! self::tableReady()) {
            return true;
        }

        return ShopContractAcceptance::where('atelier_id', $atelierId)
            ->where('contract_version', self::VERSION)
            ->exists();
    }

    /** فقط صاحب فروشگاه (نه کارمند، حسابرس یا ادمین سیستم) باید قرارداد را تأیید کند. */
    public static function isRequiredFor(User $user): bool
    {
        if (! self::tableReady() || ! $user->atelier_id) {
            return false;
        }
        if (ShopStaffAccess::isAuditor($user) || ShopStaffAccess::isPlatformAdmin($user)) {
            return false;
        }
        if (! ShopStaffAccess::isOwner($user)) {
            return false;
        }

        if (Schema::hasColumn('ateliers', 'project_type')) {
            $projectType = Atelier::where('id', $user->atelier_id)->value('project_type');
            if (ProjectType::normalize($projectType) !== ProjectType::SHOP) {
                return false;
            }
        }

        return ! self::isAccepted((int) $user->atelier_id);
    }
}
