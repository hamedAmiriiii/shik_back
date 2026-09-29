<?php

namespace App\Services\Moadian;

use RuntimeException;

/** خطای پیکربندی (کلید/گواهی/حافظه) که با تکرار برطرف نمی‌شود و ارسال فروشگاه را متوقف می‌کند. */
class MoadianConfigException extends RuntimeException
{
}
