-- کد معرف بازاریاب فقط عدد ۴ رقمی
-- کد قدیمی منو: 4Z3T6F → 4366
-- اگر شیخ هنوز 4Z3T6F دارد، این را حتماً اجرا کنید؛ وگرنه لینک mref=4366 بازاریاب را پیدا نمی‌کند.

UPDATE marketers SET code = '4366' WHERE code = '4Z3T6F'
  AND NOT EXISTS (SELECT 1 FROM (SELECT code FROM marketers WHERE code = '4366') t);

-- چک:
-- SELECT id, name, phone, code FROM marketers;
