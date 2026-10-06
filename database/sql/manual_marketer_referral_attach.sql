-- اتصال دستی آخرین فروشگاه به بازاریاب (اگر ثبت‌نام بدون انتساب بوده)
-- قبل از اجرا: id بازاریاب و atelier را چک کنید

-- بازاریاب‌ها:
-- SELECT id, name, phone, code FROM marketers;

-- آخرین فروشگاه‌ها:
-- SELECT id, name, code, created_at FROM ateliers ORDER BY id DESC LIMIT 10;

-- مثال: فروشگاه atelier_id=XX را به بازاریاب code=9834 وصل کن
-- INSERT INTO marketer_referrals (marketer_id, atelier_id, user_id, visitor_id, first_visit_at, created_at, updated_at)
-- SELECT m.id, a.id, u.id, NULL, NULL, NOW(), NOW()
-- FROM marketers m
-- CROSS JOIN ateliers a
-- LEFT JOIN users u ON u.atelier_id = a.id AND u.shop_staff_role = 'owner'
-- WHERE m.code = '9834'
--   AND a.id = /* ID فروشگاه */
--   AND NOT EXISTS (SELECT 1 FROM marketer_referrals r WHERE r.atelier_id = a.id)
-- LIMIT 1;
