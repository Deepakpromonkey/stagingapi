-- A2  Is this carrier legit / a scam / fake  (run on the carrier DB)
SET @dot := 1749;

WITH c AS (
    SELECT c.*, d.dun_bradstreet_no, d.status_code, d.safety_rating
    FROM carrier.carriers c
    LEFT JOIN carrier.carrier_details d
               ON d.id = (SELECT x.id FROM carrier.carrier_details x
                           WHERE x.dot_number = c.dot_number ORDER BY x.id DESC LIMIT 1)
    WHERE c.dot_number = @dot
),
coi AS (
    SELECT
        cx.dot_number,
        cx.status                                                   AS coi_extraction_status,
        cx.extracted_at                                             AS coi_extracted_at,
        IF(JSON_VALID(cx.extracted_json), COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiration_date'),'$[0]')),
            JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.policy_expiration_date'),'$[0]')),
            JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.policy_expiration'),'$[0]')),
            JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiry_date'),'$[0]')),
            JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiration'),'$[0]'))
        ), NULL)                                                    AS coi_expiry_raw,
        IF(JSON_VALID(cx.extracted_json), COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.insured_name'),'$[0]')),
            JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.insured'),'$[0]'))
        ), NULL)                                                    AS coi_insured_name
    FROM carrier.coi_document_extractions cx
    WHERE cx.id = (SELECT e.id FROM carrier.coi_document_extractions e
                    WHERE e.dot_number = @dot ORDER BY e.extracted_at DESC, e.id DESC LIMIT 1)
),
signals AS (
    SELECT 'Shared phone' AS check_name,
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.telephone = c.telephone AND c.telephone <> '' AND o.dot_number <> c.dot_number) AS other_carriers,
           NULL AS plain_flag FROM c
    UNION ALL
    SELECT 'Shared email',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.email_address = c.email_address AND c.email_address <> '' AND o.dot_number <> c.dot_number), NULL FROM c
    UNION ALL
    SELECT 'Shared physical address',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.phy_street = c.phy_street AND o.phy_city = c.phy_city AND o.phy_state = c.phy_state
               AND CHAR_LENGTH(TRIM(COALESCE(c.phy_street,''))) > 5 AND o.dot_number <> c.dot_number), NULL FROM c
    UNION ALL
    SELECT 'Shared DUNS number',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carrier_details o
             WHERE o.dun_bradstreet_no = c.dun_bradstreet_no
               AND CHAR_LENGTH(TRIM(COALESCE(c.dun_bradstreet_no,''))) > 3 AND o.dot_number <> c.dot_number), NULL FROM c
    UNION ALL
    SELECT 'Equipment (VIN) shared across carriers',
           (SELECT COUNT(DISTINCT i.dot_number) FROM carrier.inspections i
              JOIN (SELECT vin FROM carrier.inspections
                     WHERE dot_number = c.dot_number AND vin IS NOT NULL AND CHAR_LENGTH(vin) = 17
                     GROUP BY vin ORDER BY MAX(insp_date) DESC LIMIT 200) v ON v.vin = i.vin
             WHERE i.dot_number <> c.dot_number), NULL FROM c
    UNION ALL
    SELECT 'Virtual / mail-drop address', NULL,
           UPPER(CONCAT_WS(' | ', c.phy_street, c.mailing_street)) REGEXP
           'UPS STORE|REGUS|WEWORK|PMB |POSTAL ANNEX|MAIL BOXES ETC|MAILBOX|REGISTERED AGENT|VIRTUAL OFFICE|SUITE #' FROM c
    UNION ALL
    SELECT 'Authority under 90 days old', NULL, DATEDIFF(CURDATE(), c.add_date) < 90 FROM c
    UNION ALL
    SELECT 'MCS-150 filing gap (24+ months)', NULL,
           c.mcs150_date IS NULL OR c.mcs150_date < CURDATE() - INTERVAL 24 MONTH FROM c
    UNION ALL
    SELECT 'Holds both carrier and broker authority', NULL,
           (SELECT MAX(a.broker_stat='A') AND MAX(a.common_stat='A' OR a.contract_stat='A')
              FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) FROM c
    UNION ALL
    SELECT 'Primary contact information missing', NULL,
           (c.telephone IS NULL OR c.telephone = '') OR (c.email_address IS NULL OR c.email_address = '') FROM c
    UNION ALL
    SELECT 'No BOC-3 process agent on file', NULL,
           NOT EXISTS (SELECT 1 FROM carrier.carrier_contacts cc WHERE cc.dot_number = c.dot_number) FROM c
    UNION ALL
    SELECT 'No active BIPD insurance filing', NULL,
           NOT EXISTS (SELECT 1 FROM carrier.insurance_filings i
                        WHERE i.dot_number = c.dot_number
                          AND (UPPER(TRIM(i.ins_form_code)) IN ('91','91X') OR UPPER(i.ins_type_desc) LIKE 'BIPD%')
                          AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE())) FROM c
    UNION ALL
    SELECT 'No COI extracted for this carrier', NULL,
           (SELECT COUNT(*) FROM coi) = 0 FROM c
    UNION ALL
    SELECT 'Extracted COI expired', NULL,
           (SELECT COALESCE(STR_TO_DATE(coi_expiry_raw,'%Y-%m-%d'),
                            STR_TO_DATE(coi_expiry_raw,'%m/%d/%Y'),
                            STR_TO_DATE(coi_expiry_raw,'%d-%b-%Y')) < CURDATE() FROM coi) FROM c
    UNION ALL
    SELECT 'COI insured name does not match FMCSA legal name', NULL,
           (SELECT UPPER(TRIM(coi.coi_insured_name)) NOT LIKE CONCAT('%', UPPER(TRIM(LEFT(c.legal_name, 12))), '%')
              FROM coi WHERE coi.coi_insured_name IS NOT NULL) FROM c
    UNION ALL
    SELECT 'USDOT record not active', NULL, c.status_code <> 'A' FROM c
    UNION ALL
    SELECT 'Active out-of-service order', NULL,
           EXISTS (SELECT 1 FROM carrier.carrier_oos_orders o
                    WHERE o.dot_number = c.dot_number AND o.status = 'ACTIVE' AND o.rescind_date IS NULL) FROM c
    UNION ALL
    SELECT 'Authority revoked in last 36 months', NULL,
           EXISTS (SELECT 1 FROM carrier.carrier_authority_orders o
                    WHERE o.dot_number = c.dot_number
                      AND o.order2_effective_date > CURDATE() - INTERVAL 36 MONTH) FROM c
    UNION ALL
    SELECT 'No roadside inspection in last 24 months', NULL,
           NOT EXISTS (SELECT 1 FROM carrier.inspections i
                        WHERE i.dot_number = c.dot_number
                          AND i.insp_date >= CURDATE() - INTERVAL 24 MONTH) FROM c
    UNION ALL
    SELECT 'Fleet size reported as zero', NULL, COALESCE(c.nbr_power_unit, 0) = 0 FROM c
    UNION ALL
    SELECT 'No drivers reported', NULL, COALESCE(c.driver_total, 0) = 0 FROM c
),
rolled AS (
    SELECT
        COALESCE(other_carriers > 0, plain_flag) AS flagged,
        CONCAT(check_name, IF(COALESCE(other_carriers,0) > 0,
               CONCAT(' (', other_carriers, ' other carrier', IF(other_carriers = 1, '', 's'), ')'), '')) AS labelled
    FROM signals
)
SELECT
    (SELECT dot_number FROM c)                                      AS dot_number,
    (SELECT id         FROM c)                                      AS carrier_row_id,
    (SELECT legal_name FROM c)                                      AS company_name,
    CASE WHEN SUM(flagged = 1) > 0 THEN 'FLAGS FOUND' ELSE 'NO FLAGS' END AS identity_state,
    SUM(flagged = 1)                                                AS signals_flagged,
    COUNT(*)                                                        AS signals_total,
    SUM(flagged IS NULL)                                            AS signals_unknown,
    CONCAT(COUNT(*) - SUM(flagged = 1), ' of ', COUNT(*), ' checks passed') AS checks_passed,
    GROUP_CONCAT(CASE WHEN flagged = 1 THEN labelled END ORDER BY labelled SEPARATOR '; ') AS signals_flagged_list,
    (SELECT coi_extraction_status FROM coi)                         AS coi_extraction_status,
    (SELECT coi_extracted_at FROM coi)                              AS coi_extracted_at,
    (SELECT coi_insured_name FROM coi)                              AS coi_insured_name,
    (SELECT COALESCE(STR_TO_DATE(coi_expiry_raw,'%Y-%m-%d'),
                     STR_TO_DATE(coi_expiry_raw,'%m/%d/%Y'),
                     STR_TO_DATE(coi_expiry_raw,'%d-%b-%Y')) FROM coi) AS coi_policy_expiry_date,
    (SELECT add_date   FROM c)                                      AS registered_since,
    (SELECT ROUND(DATEDIFF(CURDATE(), add_date) / 365.25, 1) FROM c) AS years_active,
    (SELECT updated_at FROM c)                                      AS fmcsa_synced_at
FROM rolled;

-- A2b  DT score  (run on the carrier DB)
-- The DT score is calculated live from FMCSA data every time it is shown; it is
-- not stored. search_histories.dt_score is only a snapshot written when someone
-- opened a profile, so it is missing for any carrier nobody has opened yet.
-- To get the score, run dt-score.sql and put the same @dot in its `dots` CTE.
