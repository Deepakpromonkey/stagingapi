-- A6  Bulk vet by MC list  (run on the carrier DB)
SET @mc_list := 'MC264180,MC146672,MC246530';

SELECT
    CASE
        WHEN b.usdot_status <> 'A'                                  THEN 'HARD-STOP'
        WHEN b.has_active_authority = 0                             THEN 'HARD-STOP'
        WHEN b.active_oos > 0                                       THEN 'HARD-STOP'
        WHEN b.safety_rating_code IN ('U','C')                      THEN 'HARD-STOP'
        WHEN b.revocations_36mo > 0                                 THEN 'HARD-STOP'
        WHEN b.bipd_limit_usd IS NULL                               THEN 'HARD-STOP'
        WHEN b.bipd_limit_usd < b.bipd_required_usd                 THEN 'HARD-STOP'
        WHEN DATEDIFF(CURDATE(), b.add_date) < 90                   THEN 'HARD-STOP'
        WHEN b.coi_policy_expiry_date < CURDATE()                   THEN 'HARD-STOP'
        WHEN b.coi_extracted_at IS NULL                             THEN 'REVIEW'
        WHEN b.coi_policy_expiry_date < CURDATE() + INTERVAL 30 DAY THEN 'REVIEW'
        WHEN b.driver_oos_pct > 4.5 OR b.vehicle_oos_pct > 10.8     THEN 'REVIEW'
        ELSE 'PASS'
    END                                                             AS verdict,
    b.carrier_row_id,
    b.dot_number,
    b.mc_numbers,
    b.legal_name,
    b.usdot_status,
    b.safety_rating,
    b.bipd_limit_usd,
    b.bipd_required_usd,
    b.coi_extraction_status,
    b.coi_extracted_at,
    b.coi_policy_expiry_date,
    CASE
        WHEN b.coi_extracted_at IS NULL                             THEN 'No COI extracted'
        WHEN b.coi_policy_expiry_date IS NULL                       THEN 'COI on file, expiry not extracted'
        WHEN b.coi_policy_expiry_date < CURDATE()                   THEN 'EXPIRED'
        WHEN b.coi_policy_expiry_date < CURDATE() + INTERVAL 30 DAY THEN 'Expires within 30 days'
        ELSE 'Valid'
    END                                                             AS coi_insurance_check,
    b.revocations_36mo,
    b.active_oos,
    b.driver_oos_pct,
    b.vehicle_oos_pct,
    ROUND(DATEDIFF(CURDATE(), b.add_date) / 365.25, 1)              AS years_active
FROM (
    SELECT
        r.*,
        COALESCE(STR_TO_DATE(r.coi_expiry_raw,'%Y-%m-%d'),
                 STR_TO_DATE(r.coi_expiry_raw,'%m/%d/%Y'),
                 STR_TO_DATE(r.coi_expiry_raw,'%d-%b-%Y'))          AS coi_policy_expiry_date
    FROM (
        SELECT
            c.id                                                    AS carrier_row_id,
            c.dot_number,
            c.legal_name,
            c.add_date,
            d.status_code                                           AS usdot_status,
            d.safety_rating                                         AS safety_rating_code,
            CASE d.safety_rating WHEN 'S' THEN 'Satisfactory' WHEN 'C' THEN 'Conditional' WHEN 'U' THEN 'Unsatisfactory' ELSE 'Not rated' END AS safety_rating,
            (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ') FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
            (SELECT MAX(a.common_stat='A' OR a.contract_stat='A' OR a.broker_stat='A') FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS has_active_authority,
            (SELECT MAX(CAST(NULLIF(a.min_cov_amount,'') AS UNSIGNED)) * 1000 FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS bipd_required_usd,
            (SELECT COUNT(*) FROM carrier.carrier_oos_orders o WHERE o.dot_number = c.dot_number AND o.status = 'ACTIVE' AND o.rescind_date IS NULL) AS active_oos,
            (SELECT COUNT(*) FROM carrier.carrier_authority_orders o WHERE o.dot_number = c.dot_number AND o.order2_effective_date > CURDATE() - INTERVAL 36 MONTH) AS revocations_36mo,
            (SELECT MAX(CASE WHEN (UPPER(TRIM(i.ins_form_code)) IN ('91','91X') OR UPPER(i.ins_type_desc) LIKE 'BIPD%') AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE()) THEN i.max_cov_amount END) * 1000 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number) AS bipd_limit_usd,
            ROUND(100 * sms.driver_oos_insp_total  / NULLIF(sms.driver_insp_total, 0), 1)  AS driver_oos_pct,
            ROUND(100 * sms.vehicle_oos_insp_total / NULLIF(sms.vehicle_insp_total, 0), 1) AS vehicle_oos_pct,
            cx.status                                               AS coi_extraction_status,
            cx.extracted_at                                         AS coi_extracted_at,
            IF(JSON_VALID(cx.extracted_json), COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiration_date'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.policy_expiration_date'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.policy_expiration'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiry_date'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiration'),'$[0]'))
            ), NULL)                                                AS coi_expiry_raw
        FROM carrier.carriers c
        JOIN (SELECT DISTINCT a.dot_number FROM carrier.carrier_authorities a WHERE FIND_IN_SET(a.docket_number, @mc_list)) w
          ON w.dot_number = c.dot_number
        LEFT JOIN carrier.carrier_details d
               ON d.id = (SELECT x.id FROM carrier.carrier_details x
                           WHERE x.dot_number = c.dot_number ORDER BY x.id DESC LIMIT 1)
        LEFT JOIN carrier.sms_measures  sms ON sms.dot_number = c.dot_number
        LEFT JOIN carrier.coi_document_extractions cx
               ON cx.id = (SELECT e.id FROM carrier.coi_document_extractions e
                            WHERE e.dot_number = c.dot_number
                            ORDER BY e.extracted_at DESC, e.id DESC LIMIT 1)
    ) r
) b
ORDER BY verdict = 'HARD-STOP' DESC, verdict = 'REVIEW' DESC, b.legal_name;

-- A6b  DT score  (run on the carrier DB)
-- The DT score is calculated live from FMCSA data every time it is shown; it is
-- not stored. search_histories.dt_score is only a snapshot written when someone
-- opened a profile, so it is missing for any carrier nobody has opened yet.
-- To get the score, run dt-score.sql and put the DOT numbers returned above in its `dots` CTE.
