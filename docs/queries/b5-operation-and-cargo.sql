-- B5  Operation classification and cargo carried  (run on the carrier DB)
-- Cargo registrations are self-reported to FMCSA on the MCS-150.
SET @dot := 1749;

SELECT
    b.carrier_row_id,
    b.dot_number,
    b.mc_numbers,
    b.legal_name,
    b.operation_type,
    b.authorized_for_hire,
    b.exempt_for_hire,
    b.private_property,
    b.private_passenger_business,
    b.private_passenger_nonbusiness,
    b.migrant,
    b.cargo_registered,
    b.cargo_count,
    b.cargo_count >= 12                                             AS unusually_broad_cargo,
    b.hazmat_flag,
    b.cargo_limit_usd,
    b.coi_cargo_limit,
    b.coi_extraction_status,
    b.coi_extracted_at,
    b.coi_policy_expiry_date,
    CASE
        WHEN b.coi_extracted_at IS NULL                             THEN 'No COI extracted'
        WHEN b.coi_policy_expiry_date IS NULL                       THEN 'COI on file, expiry not extracted'
        WHEN b.coi_policy_expiry_date < CURDATE()                   THEN 'EXPIRED'
        WHEN b.coi_policy_expiry_date < CURDATE() + INTERVAL 30 DAY THEN 'Expires within 30 days'
        ELSE 'Valid'
    END                                                             AS coi_insurance_check
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
            CASE c.carrier_operation WHEN 'A' THEN 'Interstate' WHEN 'B' THEN 'Intrastate Hazmat' WHEN 'C' THEN 'Intrastate Non-Hazmat' ELSE c.carrier_operation END AS operation_type,
            (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ') FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
            cen.authorized_for_hire,
            cen.exempt_for_hire,
            cen.private_property,
            cen.private_passenger_business,
            cen.private_passenger_nonbusiness,
            cen.migrant,
            UPPER(COALESCE(d.crgo_chem,'')) IN ('X','Y','1')        AS hazmat_flag,
            CONCAT_WS(', ',
                IF(UPPER(COALESCE(d.crgo_genfreight,'')) IN ('X','Y','1'), 'General Freight', NULL),
                IF(UPPER(COALESCE(d.crgo_household,''))  IN ('X','Y','1'), 'Household Goods', NULL),
                IF(UPPER(COALESCE(d.crgo_metalsheet,'')) IN ('X','Y','1'), 'Metal/Sheet', NULL),
                IF(UPPER(COALESCE(d.crgo_motoveh,''))    IN ('X','Y','1'), 'Motor Vehicles', NULL),
                IF(UPPER(COALESCE(d.crgo_drivetow,''))   IN ('X','Y','1'), 'Drive/Tow Away', NULL),
                IF(UPPER(COALESCE(d.crgo_logpole,''))    IN ('X','Y','1'), 'Logs/Poles', NULL),
                IF(UPPER(COALESCE(d.crgo_bldgmat,''))    IN ('X','Y','1'), 'Building Materials', NULL),
                IF(UPPER(COALESCE(d.crgo_mobilehome,'')) IN ('X','Y','1'), 'Mobile Homes', NULL),
                IF(UPPER(COALESCE(d.crgo_machlrg,''))    IN ('X','Y','1'), 'Machinery/Large', NULL),
                IF(UPPER(COALESCE(d.crgo_produce,''))    IN ('X','Y','1'), 'Fresh Produce', NULL),
                IF(UPPER(COALESCE(d.crgo_liqgas,''))     IN ('X','Y','1'), 'Liquids/Gases', NULL),
                IF(UPPER(COALESCE(d.crgo_intermodal,'')) IN ('X','Y','1'), 'Intermodal', NULL),
                IF(UPPER(COALESCE(d.crgo_passengers,'')) IN ('X','Y','1'), 'Passengers', NULL),
                IF(UPPER(COALESCE(d.crgo_oilfield,''))   IN ('X','Y','1'), 'Oilfield Equipment', NULL),
                IF(UPPER(COALESCE(d.crgo_livestock,''))  IN ('X','Y','1'), 'Livestock', NULL),
                IF(UPPER(COALESCE(d.crgo_grainfeed,''))  IN ('X','Y','1'), 'Grain/Feed', NULL),
                IF(UPPER(COALESCE(d.crgo_coalcoke,''))   IN ('X','Y','1'), 'Coal/Coke', NULL),
                IF(UPPER(COALESCE(d.crgo_meat,''))       IN ('X','Y','1'), 'Meat', NULL),
                IF(UPPER(COALESCE(d.crgo_garbage,''))    IN ('X','Y','1'), 'Garbage/Refuse', NULL),
                IF(UPPER(COALESCE(d.crgo_usmail,''))     IN ('X','Y','1'), 'U.S. Mail', NULL),
                IF(UPPER(COALESCE(d.crgo_chem,''))       IN ('X','Y','1'), 'Chemicals', NULL),
                IF(UPPER(COALESCE(d.crgo_drybulk,''))    IN ('X','Y','1'), 'Dry Bulk', NULL),
                IF(UPPER(COALESCE(d.crgo_coldfood,''))   IN ('X','Y','1'), 'Refrigerated Food', NULL),
                IF(UPPER(COALESCE(d.crgo_beverages,''))  IN ('X','Y','1'), 'Beverages', NULL),
                IF(UPPER(COALESCE(d.crgo_paperprod,''))  IN ('X','Y','1'), 'Paper Products', NULL),
                IF(UPPER(COALESCE(d.crgo_utility,''))    IN ('X','Y','1'), 'Utility', NULL),
                IF(UPPER(COALESCE(d.crgo_farmsupp,''))   IN ('X','Y','1'), 'Farm Supplies', NULL),
                IF(UPPER(COALESCE(d.crgo_construct,''))  IN ('X','Y','1'), 'Construction', NULL),
                IF(UPPER(COALESCE(d.crgo_waterwell,''))  IN ('X','Y','1'), 'Water Well', NULL),
                IF(UPPER(COALESCE(d.crgo_cargoothr,''))  IN ('X','Y','1'), COALESCE(NULLIF(d.crgo_cargoothr_desc,''), 'Other'), NULL)
            )                                                       AS cargo_registered,
            (UPPER(COALESCE(d.crgo_genfreight,'')) IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_household,''))  IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_metalsheet,'')) IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_motoveh,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_drivetow,''))   IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_logpole,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_bldgmat,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_mobilehome,'')) IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_machlrg,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_produce,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_liqgas,''))     IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_intermodal,'')) IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_passengers,'')) IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_oilfield,''))   IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_livestock,''))  IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_grainfeed,''))  IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_coalcoke,''))   IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_meat,''))       IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_garbage,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_usmail,''))     IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_chem,''))       IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_drybulk,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_coldfood,''))   IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_beverages,''))  IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_paperprod,''))  IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_utility,''))    IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_farmsupp,''))   IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_construct,''))  IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_waterwell,''))  IN ('X','Y','1'))
          + (UPPER(COALESCE(d.crgo_cargoothr,''))  IN ('X','Y','1'))  AS cargo_count,
            (SELECT MAX(CASE WHEN (UPPER(TRIM(i.ins_form_code)) = '34' OR UPPER(i.ins_type_desc) LIKE '%CARGO%') AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE()) THEN i.max_cov_amount END) * 1000 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number) AS cargo_limit_usd,
            cx.status                                               AS coi_extraction_status,
            cx.extracted_at                                         AS coi_extracted_at,
            IF(JSON_VALID(cx.extracted_json), COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.cargo_limit'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.cargo'),'$[0]'))
            ), NULL)                                                AS coi_cargo_limit,
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
        LEFT JOIN carrier.carrier_census  cen ON cen.dot_number = c.dot_number
        LEFT JOIN carrier.coi_document_extractions cx
               ON cx.id = (SELECT e.id FROM carrier.coi_document_extractions e
                            WHERE e.dot_number = c.dot_number
                            ORDER BY e.extracted_at DESC, e.id DESC LIMIT 1)
        WHERE c.dot_number = @dot
    ) r
) b;

-- B5b  DT score  (run on the carrier DB)
-- The DT score is calculated live from FMCSA data every time it is shown; it is
-- not stored. search_histories.dt_score is only a snapshot written when someone
-- opened a profile, so it is missing for any carrier nobody has opened yet.
-- To get the score, run dt-score.sql and put the same @dot in its `dots` CTE.
