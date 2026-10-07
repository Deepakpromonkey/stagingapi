-- A3  Name search / disambiguation  (run on the carrier DB)
SET @name := 'RUNDLETT';

SELECT
    b.carrier_row_id,
    b.dot_number,
    b.mc_numbers,
    b.legal_name,
    b.dba_name,
    b.phy_city,
    b.phy_state,
    b.authority,
    b.usdot_status,
    b.safety_rating,
    b.power_units,
    b.bipd_limit_usd,
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
            c.dba_name,
            c.phy_city,
            c.phy_state,
            c.add_date,
            c.nbr_power_unit                                        AS power_units,
            d.status_code                                           AS usdot_status,
            CASE d.safety_rating WHEN 'S' THEN 'Satisfactory' WHEN 'C' THEN 'Conditional' WHEN 'U' THEN 'Unsatisfactory' ELSE 'Not rated' END AS safety_rating,
            (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ') FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
            CONCAT_WS('/',
                CONCAT('Common:',   COALESCE((SELECT MAX(a.common_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number), '-')),
                CONCAT('Contract:', COALESCE((SELECT MAX(a.contract_stat) FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number), '-')),
                CONCAT('Broker:',   COALESCE((SELECT MAX(a.broker_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number), '-'))
            )                                                       AS authority,
            (SELECT MAX(CASE WHEN (UPPER(TRIM(i.ins_form_code)) IN ('91','91X') OR UPPER(i.ins_type_desc) LIKE 'BIPD%') AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE()) THEN i.max_cov_amount END) * 1000 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number) AS bipd_limit_usd,
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
        LEFT JOIN carrier.carrier_details d
               ON d.id = (SELECT x.id FROM carrier.carrier_details x
                           WHERE x.dot_number = c.dot_number ORDER BY x.id DESC LIMIT 1)
        LEFT JOIN carrier.coi_document_extractions cx
               ON cx.id = (SELECT e.id FROM carrier.coi_document_extractions e
                            WHERE e.dot_number = c.dot_number
                            ORDER BY e.extracted_at DESC, e.id DESC LIMIT 1)
        WHERE c.legal_name LIKE CONCAT('%', @name, '%')
           OR c.dba_name   LIKE CONCAT('%', @name, '%')
        LIMIT 25
    ) r
) b
ORDER BY (b.usdot_status = 'A') DESC, b.power_units DESC;

-- A3b  DT score  (run on the carrier DB)
-- The DT score is calculated live from FMCSA data every time it is shown; it is
-- not stored. search_histories.dt_score is only a snapshot written when someone
-- opened a profile, so it is missing for any carrier nobody has opened yet.
-- To get the score, run dt-score.sql and put the DOT numbers returned above in its `dots` CTE.
