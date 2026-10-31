-- شمارهٔ مسئول ثبت نتیجه. این شخص فقط صفحهٔ اعلام نتیجهٔ همین مسابقه را می‌بیند.
ALTER TABLE `billiard_tournaments`
  ADD COLUMN `results_clerk_phone` varchar(20) NULL;
