-- B1  Authority status  (run on the carrier DB)
SET @dot := 1749;

SELECT
    b.carrier_row_id,
    b.dot_number,
    b.mc_numbers,
    b.legal_name,
    b.authority_common,
    b.authority_contract,
    b.authority_broker,
    b.has_active_authority,
    b.operation_type,
    b.usdot_status,
    b.revocations_36mo,
    IF(b.revocations_36mo > 0, 'Interrupted', 'Uninterrupted')      AS authority_history,
    b.bipd_limit_usd,
    b.cargo_limit_usd,
    b.insurance_active,
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
    b.fmcsa_synced_at
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
            c.updated_at                                            AS fmcsa_synced_at,
            CASE c.carrier_operation WHEN 'A' THEN 'Interstate' WHEN 'B' THEN 'Intrastate Hazmat' WHEN 'C' THEN 'Intrastate Non-Hazmat' ELSE c.carrier_operation END AS operation_type,
            d.status_code                                           AS usdot_status,
            (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ') FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
            (SELECT MAX(a.common_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS authority_common,
            (SELECT MAX(a.contract_stat) FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS authority_contract,
            (SELECT MAX(a.broker_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS authority_broker,
            (SELECT MAX(a.common_stat='A' OR a.contract_stat='A' OR a.broker_stat='A') FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS has_active_authority,
            (SELECT COUNT(*) FROM carrier.carrier_authority_orders o WHERE o.dot_number = c.dot_number AND o.order2_effective_date > CURDATE() - INTERVAL 36 MONTH) AS revocations_36mo,
            (SELECT MAX(CASE WHEN (UPPER(TRIM(i.ins_form_code)) IN ('91','91X') OR UPPER(i.ins_type_desc) LIKE 'BIPD%') AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE()) THEN i.max_cov_amount END) * 1000 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number) AS bipd_limit_usd,
            (SELECT MAX(CASE WHEN (UPPER(TRIM(i.ins_form_code)) = '34' OR UPPER(i.ins_type_desc) LIKE '%CARGO%') AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE()) THEN i.max_cov_amount END) * 1000 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number) AS cargo_limit_usd,
            (SELECT COUNT(*) > 0 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE())) AS insurance_active,
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
        WHERE c.dot_number = @dot
    ) r
) b;

-- B1b  DT score  (run on the carrier DB)
-- The DT score is calculated live from FMCSA data every time it is shown; it is
-- not stored. search_histories.dt_score is only a snapshot written when someone
-- opened a profile, so it is missing for any carrier nobody has opened yet.
-- To get the score, run dt-score.sql and put the same @dot in its `dots` CTE.
