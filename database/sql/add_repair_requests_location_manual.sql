-- لوکیشن درخواست تعمیر (معادل migration 2026_10_03_100000_add_location_to_repair_requests)
ALTER TABLE `repair_requests`
  ADD COLUMN `latitude` decimal(10,7) DEFAULT NULL AFTER `address`,
  ADD COLUMN `longitude` decimal(10,7) DEFAULT NULL AFTER `latitude`;
