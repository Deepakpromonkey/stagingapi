SET @company_id := 5;

SELECT
    sh.carrier_id                          AS dot_number,
    sh.dt_score,
    sh.updated_at                          AS vetted_at,
    CONCAT(u.first_name, ' ', u.last_name) AS vetted_by,
    u.email                                AS vetted_by_email
FROM search_histories sh
JOIN users u ON u.id = sh.user_id
WHERE sh.company_id = @company_id
  AND sh.updated_at >= CURDATE()                       -- today
--AND sh.updated_at >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY   -- this week
ORDER BY sh.updated_at DESC;

-- A5b — "did anyone on my team already vet this carrier?"
SET @company_id := 5;
SET @dot := 1749;

SELECT
    CONCAT(u.first_name, ' ', u.last_name) AS vetted_by,
    sh.dt_score,
    sh.updated_at                          AS vetted_at
FROM search_histories sh
JOIN users u ON u.id = sh.user_id
WHERE sh.company_id = @company_id AND sh.carrier_id = @dot
ORDER BY sh.updated_at DESC;
