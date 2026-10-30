-- شماره پایدار بازی در هر مسابقه (match_no)
ALTER TABLE `billiard_matches`
  ADD COLUMN `match_no` int unsigned NULL AFTER `tournament_id`;

-- شماره‌گذاری رکوردهای فعلی به ترتیب ایجاد
SET @tid := NULL;
SET @n := 0;
UPDATE `billiard_matches` m
JOIN (
  SELECT id,
         tournament_id,
         @n := IF(@tid = tournament_id, @n + 1, 1) AS new_no,
         @tid := tournament_id
  FROM `billiard_matches`
  ORDER BY tournament_id, id
) x ON x.id = m.id
SET m.match_no = x.new_no;

ALTER TABLE `billiard_matches`
  ADD UNIQUE KEY `billiard_matches_tournament_match_no_unique` (`tournament_id`, `match_no`);
