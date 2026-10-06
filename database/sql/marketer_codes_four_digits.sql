-- کد معرف بازاریاب فقط عدد ۴ رقمی
-- کد قدیمی منو: 4Z3T6F → 4366

UPDATE marketers SET code = '4366' WHERE code = '4Z3T6F'
  AND NOT EXISTS (SELECT 1 FROM (SELECT code FROM marketers WHERE code = '4366') t);
