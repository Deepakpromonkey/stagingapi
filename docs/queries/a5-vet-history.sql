-- A5  Vet history for the team  (run on the app DB: newbrokerapi)
-- dt_score here is the snapshot captured when that vet was run, which is what a
-- history view wants. It is NOT the carrier's current score: the DT score is
-- recalculated live from FMCSA data on every view (see dt-score.sql), and this
-- column is null for rows written before the score was captured.
SET @company_id := 5;

SELECT
    sh.carrier_id                          AS carrier_row_id,
    sh.company_name                        AS carrier_name,
    sh.dt_score,
    CASE WHEN sh.dt_score >= 90 THEN 'A' WHEN sh.dt_score >= 80 THEN 'B' WHEN sh.dt_score >= 70 THEN 'C' WHEN sh.dt_score >= 60 THEN 'D' ELSE 'F' END AS dt_grade,
    CASE WHEN sh.dt_score >= 80 THEN 'Approved' WHEN sh.dt_score >= 60 THEN 'Review' ELSE 'High Risk' END AS dt_band,
    sh.updated_at                          AS vetted_at,
    CONCAT(u.first_name, ' ', u.last_name) AS vetted_by,
    u.email                                AS vetted_by_email
FROM search_histories sh
JOIN users u ON u.id = sh.user_id
WHERE sh.company_id = @company_id
  AND sh.updated_at >= CURDATE()
ORDER BY sh.updated_at DESC;


-- A5b  Same, for the current week  (run on the app DB: newbrokerapi)
SET @company_id := 5;

SELECT
    sh.carrier_id                          AS carrier_row_id,
    sh.company_name                        AS carrier_name,
    sh.dt_score,
    CASE WHEN sh.dt_score >= 80 THEN 'Approved' WHEN sh.dt_score >= 60 THEN 'Review' ELSE 'High Risk' END AS dt_band,
    sh.updated_at                          AS vetted_at,
    CONCAT(u.first_name, ' ', u.last_name) AS vetted_by
FROM search_histories sh
JOIN users u ON u.id = sh.user_id
WHERE sh.company_id = @company_id
  AND sh.updated_at >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY
ORDER BY sh.updated_at DESC;


-- A5c  Did anyone on my team already vet this carrier  (run on the app DB: newbrokerapi)
SET @company_id := 5;
SET @carrier_row_id := 0;

SELECT
    CONCAT(u.first_name, ' ', u.last_name) AS vetted_by,
    u.email                                AS vetted_by_email,
    sh.dt_score,
    CASE WHEN sh.dt_score >= 80 THEN 'Approved' WHEN sh.dt_score >= 60 THEN 'Review' ELSE 'High Risk' END AS dt_band,
    sh.updated_at                          AS vetted_at
FROM search_histories sh
JOIN users u ON u.id = sh.user_id
WHERE sh.company_id = @company_id
  AND sh.carrier_id = @carrier_row_id
ORDER BY sh.updated_at DESC;


-- A5d  COI chase requests raised by the team  (run on the app DB: newbrokerapi)
SET @company_id := 5;

SELECT
    q.dot_number,
    q.carrier_name,
    q.carrier_mc,
    q.status                               AS coi_request_status,
    q.recipient_email,
    q.recipient_source,
    COALESCE(p.extracted_expiry_date, q.insurance_expiry_date) AS coi_policy_expiry_date,
    CASE
        WHEN COALESCE(p.extracted_expiry_date, q.insurance_expiry_date) IS NULL THEN 'No expiry extracted'
        WHEN COALESCE(p.extracted_expiry_date, q.insurance_expiry_date) < CURDATE() THEN 'EXPIRED'
        WHEN COALESCE(p.extracted_expiry_date, q.insurance_expiry_date) < CURDATE() + INTERVAL 30 DAY THEN 'Expires within 30 days'
        ELSE 'Valid'
    END                                    AS coi_insurance_check,
    q.sent_at,
    q.responded_at,
    CONCAT(u.first_name, ' ', u.last_name) AS requested_by
FROM coi_insurance_requests q
JOIN users u ON u.id = q.user_id
LEFT JOIN coi_insurance_responses p
       ON p.id = (SELECT x.id FROM coi_insurance_responses x
                   WHERE x.coi_insurance_request_id = q.id
                   ORDER BY x.received_at DESC, x.id DESC LIMIT 1)
WHERE q.company_id = @company_id
ORDER BY q.created_at DESC
LIMIT 100;
