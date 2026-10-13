-- حالت ترکیب لایه منوی سه‌بعدی روی کالای تولیدی
-- side = نمای کنار (ساندویچ) | overlay = نمای بالا (پیتزا)
-- اگر ستون از قبل هست خطای Duplicate column می‌دهد و می‌توانید نادیده بگیرید.

ALTER TABLE `produced_goods`
  ADD COLUMN `layer_compose` VARCHAR(16) NOT NULL DEFAULT 'side'
  AFTER `layer_order`;
