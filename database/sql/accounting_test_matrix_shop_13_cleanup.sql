-- پاک کردن فقط اسناد تست [TEST-MATRIX] فروشگاه ۱۳
-- دادهٔ واقعی فروشگاه دست نمی‌خورد.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;
SET @aid = 13;
SET @tag = CAST('[TEST-MATRIX]' AS CHAR CHARSET utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @pat = CAST('[TEST-MATRIX]%' AS CHAR CHARSET utf8mb4) COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 0;

DELETE l FROM accounting_lines l
INNER JOIN accounting_vouchers v ON v.id = l.voucher_id
WHERE v.atelier_id = @aid
  AND v.description LIKE @pat;

DELETE FROM accounting_vouchers
WHERE atelier_id = @aid
  AND description LIKE @pat;

SET FOREIGN_KEY_CHECKS = 1;

SELECT COUNT(*) AS remaining_test_vouchers
FROM accounting_vouchers
WHERE atelier_id = 13
  AND description LIKE @pat;
