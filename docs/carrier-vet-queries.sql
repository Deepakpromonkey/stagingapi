SET @dot := 1749;

WITH c AS (
    SELECT c.*, d.dun_bradstreet_no
    FROM carrier.carriers c
    LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
    WHERE c.dot_number = @dot
),
area_codes AS (
    SELECT 'AL' st, '205,251,256,334,659,938' codes UNION ALL SELECT 'AK','907' UNION ALL
    SELECT 'AZ','480,520,602,623,928' UNION ALL SELECT 'AR','479,501,870' UNION ALL
    SELECT 'CA','209,213,279,310,323,341,350,408,415,424,442,510,530,559,562,619,626,628,650,657,661,669,707,714,747,760,805,818,820,831,840,858,909,916,925,949,951' UNION ALL
    SELECT 'CO','303,719,720,970,983' UNION ALL SELECT 'CT','203,475,860,959' UNION ALL
    SELECT 'DE','302' UNION ALL SELECT 'DC','202,771' UNION ALL
    SELECT 'FL','239,305,321,352,386,407,448,561,656,689,727,754,772,786,813,850,863,904,941,954' UNION ALL
    SELECT 'GA','229,404,470,478,678,706,762,770,912,943' UNION ALL SELECT 'HI','808' UNION ALL
    SELECT 'ID','208,986' UNION ALL
    SELECT 'IL','217,224,309,312,331,447,464,618,630,708,773,779,815,847,872' UNION ALL
    SELECT 'IN','219,260,317,463,574,765,812,930' UNION ALL SELECT 'IA','319,515,563,641,712' UNION ALL
    SELECT 'KS','316,620,785,913' UNION ALL SELECT 'KY','270,364,502,606,859' UNION ALL
    SELECT 'LA','225,318,337,504,985' UNION ALL SELECT 'ME','207' UNION ALL
    SELECT 'MD','240,301,410,443,667' UNION ALL SELECT 'MA','339,351,413,508,617,774,781,857,978' UNION ALL
    SELECT 'MI','231,248,269,313,517,586,616,679,734,810,906,947,989' UNION ALL
    SELECT 'MN','218,320,507,612,651,763,952' UNION ALL SELECT 'MS','228,601,662,769' UNION ALL
    SELECT 'MO','314,417,557,573,636,660,816,975' UNION ALL SELECT 'MT','406' UNION ALL
    SELECT 'NE','308,402,531' UNION ALL SELECT 'NV','702,725,775' UNION ALL SELECT 'NH','603' UNION ALL
    SELECT 'NJ','201,551,609,640,732,848,856,862,908,973' UNION ALL SELECT 'NM','505,575' UNION ALL
    SELECT 'NY','212,315,332,347,363,516,518,585,607,631,646,680,716,718,838,845,914,917,929,934' UNION ALL
    SELECT 'NC','252,336,472,704,743,828,910,919,980,984' UNION ALL SELECT 'ND','701' UNION ALL
    SELECT 'OH','216,220,234,283,326,330,380,419,440,513,567,614,740,937' UNION ALL
    SELECT 'OK','405,539,572,580,918' UNION ALL SELECT 'OR','458,503,541,971' UNION ALL
    SELECT 'PA','215,223,267,272,412,445,484,570,582,610,717,724,814,835,878' UNION ALL
    SELECT 'RI','401' UNION ALL SELECT 'SC','803,821,839,843,854,864' UNION ALL SELECT 'SD','605' UNION ALL
    SELECT 'TN','423,615,629,731,865,901,931' UNION ALL
    SELECT 'TX','210,214,254,281,325,346,361,409,430,432,469,512,682,713,726,737,806,817,830,832,903,915,936,940,945,956,972,979' UNION ALL
    SELECT 'UT','385,435,801' UNION ALL SELECT 'VT','802' UNION ALL
    SELECT 'VA','276,434,540,571,703,757,804,826,948' UNION ALL SELECT 'WA','206,253,360,425,509,564' UNION ALL
    SELECT 'WV','304,681' UNION ALL SELECT 'WI','262,274,414,534,608,715,920' UNION ALL SELECT 'WY','307'
),

signals AS (
    SELECT 'Shared phone' AS check_name,
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.telephone = c.telephone AND c.telephone <> '' AND o.dot_number <> c.dot_number) AS other_carriers,
           NULL AS plain_flag FROM c
    UNION ALL
    SELECT 'Shared email',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.email_address = c.email_address AND c.email_address <> '' AND o.dot_number <> c.dot_number),
           NULL FROM c
    UNION ALL
    SELECT 'Shared physical address',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.phy_street = c.phy_street AND o.phy_city = c.phy_city AND o.phy_state = c.phy_state
               AND CHAR_LENGTH(TRIM(COALESCE(c.phy_street,''))) > 5 AND o.dot_number <> c.dot_number),
           NULL FROM c
    UNION ALL
    SELECT 'Shared DUNS number',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carrier_details o
             WHERE o.dun_bradstreet_no = c.dun_bradstreet_no
               AND CHAR_LENGTH(TRIM(COALESCE(c.dun_bradstreet_no,''))) > 3 AND o.dot_number <> c.dot_number),
           NULL FROM c
    UNION ALL
    -- VIN lineage: other carriers inspected on this carrier's most recent VINs.
    SELECT 'Equipment (VIN) shared across carriers',
           (SELECT COUNT(DISTINCT i.dot_number)
              FROM carrier.inspections i
              JOIN (SELECT vin FROM carrier.inspections
                     WHERE dot_number = c.dot_number AND vin IS NOT NULL AND CHAR_LENGTH(vin) = 17
                     GROUP BY vin ORDER BY MAX(insp_date) DESC LIMIT 200) v ON v.vin = i.vin
             WHERE i.dot_number <> c.dot_number),
           NULL FROM c
    UNION ALL
    SELECT 'Virtual / mail-drop address', NULL,
           UPPER(CONCAT_WS(' | ', c.phy_street, c.mailing_street)) REGEXP
           'UPS STORE|REGUS|WEWORK|PMB |POSTAL ANNEX|MAIL BOXES ETC|MAILBOX|REGISTERED AGENT|VIRTUAL OFFICE|SUITE #' FROM c
    UNION ALL
    SELECT 'Authority under 90 days old', NULL, DATEDIFF(CURDATE(), c.add_date) < 90 FROM c
    UNION ALL
    SELECT 'Area code does not match business state', NULL,
           IF(c.telephone IS NULL OR CHAR_LENGTH(REGEXP_REPLACE(c.telephone,'[^0-9]','')) < 10 OR ac.codes IS NULL,
              NULL,
              FIND_IN_SET(LEFT(RIGHT(REGEXP_REPLACE(c.telephone,'[^0-9]',''), 10), 3), ac.codes) = 0)
    FROM c LEFT JOIN area_codes ac ON ac.st = c.phy_state
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
),
rolled AS (
    SELECT
     
        COALESCE(other_carriers > 0, plain_flag)                          AS flagged,
        CONCAT(check_name,
               IF(COALESCE(other_carriers, 0) > 0,
                  CONCAT(' (', other_carriers, ' other carrier',
                         IF(other_carriers = 1, '', 's'), ')'), ''))      AS labelled
    FROM signals
)
SELECT
    CASE WHEN SUM(flagged = 1) > 0 THEN 'FLAGS FOUND' ELSE 'NO FLAGS' END  AS identity_state,
    SUM(flagged = 1)                                                       AS signals_flagged,
    COUNT(*)                                                               AS signals_total,
    SUM(flagged IS NULL)                                                   AS signals_unknown,
    GROUP_CONCAT(CASE WHEN flagged = 1 THEN labelled END
                 ORDER BY labelled SEPARATOR '; ')                         AS signals_flagged_list,
    -- Identity anchors, so the one-line answer can name who was checked.
    (SELECT dot_number   FROM c)                                           AS dot_number,
    (SELECT legal_name   FROM c)                                           AS company_name,
    (SELECT add_date     FROM c)                                           AS registered_since,
    (SELECT ROUND(DATEDIFF(CURDATE(), add_date) / 365.25, 1) FROM c)       AS years_active,
    (SELECT updated_at   FROM c)                                           AS fmcsa_synced_at
FROM rolled;

SET @dot := 1749;

WITH c AS (
    SELECT c.*, d.dun_bradstreet_no
    FROM carrier.carriers c
    LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
    WHERE c.dot_number = @dot
),
area_codes AS (
    SELECT 'AL' st, '205,251,256,334,659,938' codes UNION ALL SELECT 'AK','907' UNION ALL
    SELECT 'AZ','480,520,602,623,928' UNION ALL SELECT 'AR','479,501,870' UNION ALL
    SELECT 'CA','209,213,279,310,323,341,350,408,415,424,442,510,530,559,562,619,626,628,650,657,661,669,707,714,747,760,805,818,820,831,840,858,909,916,925,949,951' UNION ALL
    SELECT 'CO','303,719,720,970,983' UNION ALL SELECT 'CT','203,475,860,959' UNION ALL
    SELECT 'DE','302' UNION ALL SELECT 'DC','202,771' UNION ALL
    SELECT 'FL','239,305,321,352,386,407,448,561,656,689,727,754,772,786,813,850,863,904,941,954' UNION ALL
    SELECT 'GA','229,404,470,478,678,706,762,770,912,943' UNION ALL SELECT 'HI','808' UNION ALL
    SELECT 'ID','208,986' UNION ALL
    SELECT 'IL','217,224,309,312,331,447,464,618,630,708,773,779,815,847,872' UNION ALL
    SELECT 'IN','219,260,317,463,574,765,812,930' UNION ALL SELECT 'IA','319,515,563,641,712' UNION ALL
    SELECT 'KS','316,620,785,913' UNION ALL SELECT 'KY','270,364,502,606,859' UNION ALL
    SELECT 'LA','225,318,337,504,985' UNION ALL SELECT 'ME','207' UNION ALL
    SELECT 'MD','240,301,410,443,667' UNION ALL SELECT 'MA','339,351,413,508,617,774,781,857,978' UNION ALL
    SELECT 'MI','231,248,269,313,517,586,616,679,734,810,906,947,989' UNION ALL
    SELECT 'MN','218,320,507,612,651,763,952' UNION ALL SELECT 'MS','228,601,662,769' UNION ALL
    SELECT 'MO','314,417,557,573,636,660,816,975' UNION ALL SELECT 'MT','406' UNION ALL
    SELECT 'NE','308,402,531' UNION ALL SELECT 'NV','702,725,775' UNION ALL SELECT 'NH','603' UNION ALL
    SELECT 'NJ','201,551,609,640,732,848,856,862,908,973' UNION ALL SELECT 'NM','505,575' UNION ALL
    SELECT 'NY','212,315,332,347,363,516,518,585,607,631,646,680,716,718,838,845,914,917,929,934' UNION ALL
    SELECT 'NC','252,336,472,704,743,828,910,919,980,984' UNION ALL SELECT 'ND','701' UNION ALL
    SELECT 'OH','216,220,234,283,326,330,380,419,440,513,567,614,740,937' UNION ALL
    SELECT 'OK','405,539,572,580,918' UNION ALL SELECT 'OR','458,503,541,971' UNION ALL
    SELECT 'PA','215,223,267,272,412,445,484,570,582,610,717,724,814,835,878' UNION ALL
    SELECT 'RI','401' UNION ALL SELECT 'SC','803,821,839,843,854,864' UNION ALL SELECT 'SD','605' UNION ALL
    SELECT 'TN','423,615,629,731,865,901,931' UNION ALL
    SELECT 'TX','210,214,254,281,325,346,361,409,430,432,469,512,682,713,726,737,806,817,830,832,903,915,936,940,945,956,972,979' UNION ALL
    SELECT 'UT','385,435,801' UNION ALL SELECT 'VT','802' UNION ALL
    SELECT 'VA','276,434,540,571,703,757,804,826,948' UNION ALL SELECT 'WA','206,253,360,425,509,564' UNION ALL
    SELECT 'WV','304,681' UNION ALL SELECT 'WI','262,274,414,534,608,715,920' UNION ALL SELECT 'WY','307'
),
signals AS (
    SELECT 'Shared phone' AS check_name,
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.telephone = c.telephone AND c.telephone <> '' AND o.dot_number <> c.dot_number) AS other_carriers,
           c.telephone AS value FROM c
    UNION ALL
    SELECT 'Shared email',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.email_address = c.email_address AND c.email_address <> '' AND o.dot_number <> c.dot_number),
           c.email_address FROM c
    UNION ALL
    SELECT 'Shared physical address',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
             WHERE o.phy_street = c.phy_street AND o.phy_city = c.phy_city AND o.phy_state = c.phy_state
               AND CHAR_LENGTH(TRIM(COALESCE(c.phy_street,''))) > 5 AND o.dot_number <> c.dot_number),
           CONCAT_WS(', ', c.phy_street, c.phy_city, c.phy_state) FROM c
    UNION ALL
    SELECT 'Shared DUNS number',
           (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carrier_details o
             WHERE o.dun_bradstreet_no = c.dun_bradstreet_no
               AND CHAR_LENGTH(TRIM(COALESCE(c.dun_bradstreet_no,''))) > 3 AND o.dot_number <> c.dot_number),
           c.dun_bradstreet_no FROM c
    UNION ALL
    SELECT 'Equipment (VIN) shared across carriers',
           (SELECT COUNT(DISTINCT i.dot_number)
              FROM carrier.inspections i
              JOIN (SELECT vin FROM carrier.inspections
                     WHERE dot_number = c.dot_number AND vin IS NOT NULL AND CHAR_LENGTH(vin) = 17
                     GROUP BY vin ORDER BY MAX(insp_date) DESC LIMIT 200) v ON v.vin = i.vin
             WHERE i.dot_number <> c.dot_number),
           NULL FROM c
    UNION ALL
    SELECT 'Virtual / mail-drop address',
           UPPER(CONCAT_WS(' | ', c.phy_street, c.mailing_street)) REGEXP
           'UPS STORE|REGUS|WEWORK|PMB |POSTAL ANNEX|MAIL BOXES ETC|MAILBOX|REGISTERED AGENT|VIRTUAL OFFICE|SUITE #',
           CONCAT_WS(', ', c.phy_street, c.phy_city) FROM c
    UNION ALL
    SELECT 'Authority under 90 days old',
           DATEDIFF(CURDATE(), c.add_date) < 90, CAST(c.add_date AS CHAR) FROM c
    UNION ALL
    SELECT 'Area code does not match business state',
           IF(c.telephone IS NULL OR CHAR_LENGTH(REGEXP_REPLACE(c.telephone,'[^0-9]','')) < 10 OR ac.codes IS NULL,
              NULL,
              FIND_IN_SET(LEFT(RIGHT(REGEXP_REPLACE(c.telephone,'[^0-9]',''), 10), 3), ac.codes) = 0),
           c.telephone
    FROM c LEFT JOIN area_codes ac ON ac.st = c.phy_state
    UNION ALL
    SELECT 'MCS-150 filing gap (24+ months)',
           c.mcs150_date IS NULL OR c.mcs150_date < CURDATE() - INTERVAL 24 MONTH,
           CAST(c.mcs150_date AS CHAR) FROM c
    UNION ALL
    SELECT 'Holds both carrier and broker authority',
           (SELECT MAX(a.broker_stat='A') AND MAX(a.common_stat='A' OR a.contract_stat='A')
              FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number),
           NULL FROM c
    UNION ALL
    SELECT 'Primary contact information missing',
           (c.telephone IS NULL OR c.telephone = '') OR (c.email_address IS NULL OR c.email_address = ''),
           NULL FROM c
)
SELECT
    check_name,
    CASE WHEN other_carriers IS NULL THEN 'unknown'
         WHEN other_carriers > 0     THEN 'FLAGGED'
         ELSE 'clear' END               AS state,
    other_carriers                      AS detail_count,   
    value,
 
    SUM(other_carriers > 0)  OVER ()    AS checks_failed,
    COUNT(*)                 OVER ()    AS checks_total
FROM signals
ORDER BY (other_carriers > 0) DESC, check_name;


-- ----------------------------------------------------------------------------
-- A3 — Name search / disambiguation. Returns every plausible match so the
-- caller can ask "which one?" before acting (GR-14).
-- ----------------------------------------------------------------------------
SET @name := 'RUNDLETT';

SELECT
    c.dot_number,
    (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ')
       FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
    c.legal_name,
    c.dba_name,
    c.phy_state,
    c.phy_city,
    CONCAT_WS('/',
        NULLIF(CONCAT('Common:',  (SELECT MAX(common_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)), 'Common:'),
        NULLIF(CONCAT('Contract:',(SELECT MAX(contract_stat) FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)), 'Contract:'),
        NULLIF(CONCAT('Broker:',  (SELECT MAX(broker_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)), 'Broker:')
    )                                                                        AS authority,
    d.status_code                                                            AS usdot_status,
    c.nbr_power_unit                                                         AS power_units
FROM carrier.carriers c
LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
WHERE c.legal_name LIKE CONCAT('%', @name, '%')
   OR c.dba_name   LIKE CONCAT('%', @name, '%')
ORDER BY (d.status_code = 'A') DESC, c.nbr_power_unit DESC
LIMIT 25;



-- ----------------------------------------------------------------------------
-- A4 — Compare carriers, side by side. Add DOTs to the IN list.
-- "Better" is score-relative to the broker's weights and must be stated as
-- such (GR-10) — this query returns the factual columns only.
-- ----------------------------------------------------------------------------
SET @dots := '1749,4161876';

SELECT
    c.dot_number,
    (SELECT GROUP_CONCAT(DISTINCT a.docket_number SEPARATOR ', ')
       FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)  AS mc_numbers,
    c.legal_name,
    (SELECT MAX(a.common_stat='A' OR a.contract_stat='A' OR a.broker_stat='A')
       FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)  AS has_active_authority,
    d.status_code                                                             AS usdot_status,
    (SELECT MAX(CASE WHEN (UPPER(TRIM(i.ins_form_code)) IN ('91','91X') OR UPPER(i.ins_type_desc) LIKE 'BIPD%')
                      AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE())
                THEN i.max_cov_amount END) * 1000
       FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number)    AS bipd_limit_usd,
    (SELECT MIN(i.cancl_effective_date)
       FROM carrier.insurance_filings i
      WHERE i.dot_number = c.dot_number AND i.cancl_effective_date > CURDATE()) AS next_insurance_expiry,
    CASE d.safety_rating WHEN 'S' THEN 'Satisfactory' WHEN 'C' THEN 'Conditional'
                         WHEN 'U' THEN 'Unsatisfactory' ELSE 'Not rated' END  AS safety_rating,
    ROUND(100 * sms.driver_oos_insp_total  / NULLIF(sms.driver_insp_total, 0), 1)  AS driver_oos_pct,
    ROUND(100 * sms.vehicle_oos_insp_total / NULLIF(sms.vehicle_insp_total, 0), 1) AS vehicle_oos_pct,
    (SELECT COUNT(*) FROM carrier.crashes cr
      WHERE cr.dot_number = c.dot_number AND cr.report_date >= CURDATE() - INTERVAL 24 MONTH) AS crashes_24mo,
    c.nbr_power_unit                                                          AS fleet_size,
    ROUND(DATEDIFF(CURDATE(), c.add_date) / 365.25, 1)                        AS years_active,
    (SELECT COUNT(*) FROM carrier.carrier_authority_orders o
      WHERE o.dot_number = c.dot_number
        AND o.order2_effective_date > CURDATE() - INTERVAL 36 MONTH)          AS revocations_36mo
FROM carrier.carriers c
LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
LEFT JOIN carrier.sms_measures  sms ON sms.dot_number = c.dot_number
WHERE FIND_IN_SET(c.dot_number, @dots)
ORDER BY FIND_IN_SET(c.dot_number, @dots);

-- A4-onboarding — onboarding status for the same DOTs (newbrokerapi).
SET @dots := '1749,4161876';
SET @company_id := 5;

SELECT carrier_dot_number, carrier_legal_name, status, created_at, signed_at
FROM carrier_connect_requests
WHERE company_id = @company_id AND FIND_IN_SET(carrier_dot_number, @dots);


-- ----------------------------------------------------------------------------
-- A5 — Vet history. Tenant-wide, not just the asking user (GR-6).
-- Runs on newbrokerapi; carrier names come from a second pass on the feed.
-- ----------------------------------------------------------------------------
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


-- ----------------------------------------------------------------------------
-- A6 — Bulk vet. Same gate logic as A1, one row per carrier, hard-stops first.
-- Feed the MC list through A0's resolution first, or pass DOTs directly.
-- Over ~10 carriers this should run as a batch job, not inline.
-- ----------------------------------------------------------------------------
SET @mc_list := 'MC246530,MC1246530';   -- or set @dot_list directly

WITH wanted AS (
    SELECT DISTINCT a.dot_number
    FROM carrier.carrier_authorities a
    WHERE FIND_IN_SET(a.docket_number, @mc_list)
),
scored AS (
    SELECT
        c.dot_number,
        (SELECT GROUP_CONCAT(DISTINCT a.docket_number SEPARATOR ', ')
           FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)   AS mc_numbers,
        c.legal_name,
        d.status_code,
        d.safety_rating,
        c.add_date,
        (SELECT MAX(a.common_stat='A' OR a.contract_stat='A' OR a.broker_stat='A')
           FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)   AS has_active_authority,
        (SELECT MAX(CAST(NULLIF(a.min_cov_amount,'') AS UNSIGNED))
           FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)   AS bipd_required_k,
        (SELECT COUNT(*) FROM carrier.carrier_oos_orders o
          WHERE o.dot_number = c.dot_number AND o.status = 'ACTIVE' AND o.rescind_date IS NULL) AS active_oos,
        (SELECT COUNT(*) FROM carrier.carrier_authority_orders o
          WHERE o.dot_number = c.dot_number
            AND o.order2_effective_date > CURDATE() - INTERVAL 36 MONTH)           AS revocations_36mo,
        (SELECT MAX(CASE WHEN (UPPER(TRIM(i.ins_form_code)) IN ('91','91X') OR UPPER(i.ins_type_desc) LIKE 'BIPD%')
                          AND (i.cancl_effective_date IS NULL OR i.cancl_effective_date > CURDATE())
                    THEN i.max_cov_amount END)
           FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number)     AS bipd_k
    FROM carrier.carriers c
    JOIN wanted w ON w.dot_number = c.dot_number
    LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
)
SELECT
    CASE
        WHEN status_code <> 'A'                     THEN 'HARD-STOP'
        WHEN has_active_authority = 0               THEN 'HARD-STOP'
        WHEN active_oos > 0                         THEN 'HARD-STOP'
        WHEN safety_rating IN ('U','C')             THEN 'HARD-STOP'
        WHEN revocations_36mo > 0                   THEN 'HARD-STOP'
        WHEN bipd_k IS NULL                         THEN 'HARD-STOP'
        WHEN bipd_k < bipd_required_k               THEN 'HARD-STOP'
        WHEN DATEDIFF(CURDATE(), add_date) < 90     THEN 'HARD-STOP'
        ELSE 'REVIEW / PASS — run A1 for the full gate'
    END                                             AS verdict,
    mc_numbers, dot_number, legal_name, status_code, safety_rating,
    bipd_k * 1000                                   AS bipd_limit_usd,
    revocations_36mo, active_oos,
    ROUND(DATEDIFF(CURDATE(), add_date) / 365.25, 1) AS years_active
FROM scored
ORDER BY verdict = 'HARD-STOP' DESC, legal_name;


-- ============================================================================
-- SECTION B — CARRIER PROFILE FACTS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- B1 — Authority status, operation type, authority history.
-- ----------------------------------------------------------------------------
SET @dot := 1749;

SELECT
    c.legal_name,
    a.docket_number,
    CASE a.common_stat   WHEN 'A' THEN 'Active' WHEN 'I' THEN 'Inactive' WHEN 'N' THEN 'None'
                         WHEN 'P' THEN 'Pending' ELSE a.common_stat END   AS common_authority,
    CASE a.contract_stat WHEN 'A' THEN 'Active' WHEN 'I' THEN 'Inactive' WHEN 'N' THEN 'None'
                         WHEN 'P' THEN 'Pending' ELSE a.contract_stat END AS contract_authority,
    CASE a.broker_stat   WHEN 'A' THEN 'Active' WHEN 'I' THEN 'Inactive' WHEN 'N' THEN 'None'
                         WHEN 'P' THEN 'Pending' ELSE a.broker_stat END   AS broker_authority,
    a.common_rev_pend, a.contract_rev_pend, a.broker_rev_pend,            -- revocation pending flags
    CASE c.carrier_operation
        WHEN 'A' THEN 'Interstate'
        WHEN 'B' THEN 'Intrastate Hazmat'
        WHEN 'C' THEN 'Intrastate Non-Hazmat'
        ELSE c.carrier_operation END                                      AS operation,
    d.status_code                                                         AS usdot_status,
    (SELECT COUNT(*) FROM carrier.carrier_authority_orders o
      WHERE o.dot_number = c.dot_number)                                  AS authority_orders_all_time,
    (SELECT COUNT(*) FROM carrier.carrier_authority_orders o
      WHERE o.dot_number = c.dot_number
        AND o.order2_effective_date > CURDATE() - INTERVAL 36 MONTH)      AS revocations_last_36mo,
    IF((SELECT COUNT(*) FROM carrier.carrier_authority_orders o WHERE o.dot_number = c.dot_number) = 0,
       'uninterrupted', 'interrupted')                                    AS authority_history,
    GREATEST(COALESCE(c.updated_at,'1970-01-01'), COALESCE(a.updated_at,'1970-01-01')) AS fmcsa_synced_at
FROM carrier.carriers c
LEFT JOIN carrier.carrier_authorities a ON a.dot_number = c.dot_number
LEFT JOIN carrier.carrier_details     d ON d.dot_number = c.dot_number
WHERE c.dot_number = @dot;

-- B1b — the authority event log behind "interrupted".
SELECT docket_number, type_license, order1_serve_date, order2_type_desc, order2_effective_date
FROM carrier.carrier_authority_orders
WHERE dot_number = @dot
ORDER BY order2_effective_date DESC;

SELECT docket_number, op_auth_type, original_action_desc, orig_served_date,
       disp_action_desc, disp_decided_date, disp_served_date
FROM carrier.carrier_authority_history
WHERE dot_number = @dot
ORDER BY orig_served_date DESC;


-- ----------------------------------------------------------------------------
-- B2 — Age / history. "How long in business", revocations, filings current.
-- ----------------------------------------------------------------------------
SET @dot := 1749;

SELECT
    c.legal_name,
    c.add_date                                                            AS authority_granted,
    ROUND(DATEDIFF(CURDATE(), c.add_date) / 365.25, 1)                    AS years_active,
    DATEDIFF(CURDATE(), c.add_date)                                       AS days_active,
    DATEDIFF(CURDATE(), c.add_date) < 90                                  AS authority_under_90_days,   -- gate-relevant
    DATEDIFF(CURDATE(), c.add_date) >= 1826                               AS five_plus_years,
    (SELECT COUNT(*) FROM carrier.carrier_authority_orders o
      WHERE o.dot_number = c.dot_number
        AND o.order2_effective_date > CURDATE() - INTERVAL 36 MONTH)      AS revocations_last_36mo,
    (SELECT COUNT(*) FROM carrier.carrier_authority_orders o
      WHERE o.dot_number = c.dot_number)                                  AS revocations_all_time,
    d.prior_revoke_flag,
    d.prior_revoke_dot_number                                             AS prior_dot_if_reincarnated,
    c.mcs150_date,
    c.mcs150_date > CURDATE() - INTERVAL 24 MONTH                         AS mcs150_current,
    TIMESTAMPDIFF(MONTH, c.mcs150_date, CURDATE())                        AS months_since_mcs150,
    (SELECT COUNT(*) FROM carrier.carrier_contacts x WHERE x.dot_number = c.dot_number) > 0 AS boc3_on_file
FROM carrier.carriers c
LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
WHERE c.dot_number = @dot;


-- ----------------------------------------------------------------------------
-- B3 — Contact info, with the identity note attached (GR-7): a shared phone or
-- email is a fraud signal the broker must see before they pick up the phone.
-- ----------------------------------------------------------------------------
SET @dot := 1749;

SELECT
    c.legal_name,
    COALESCE(NULLIF(c.telephone, ''), d.phone)                            AS dispatch_phone,
    d.cell_phone,
    c.fax,
    c.email_address                                                       AS official_email,
    CONCAT_WS(', ', c.phy_street, c.phy_city, c.phy_state, c.phy_zip)     AS physical_address,
    CONCAT_WS(', ', c.mailing_street, c.mailing_city, c.mailing_state, c.mailing_zip) AS mailing_address,
    d.company_officer_1, d.company_officer_2,

    -- identity notes
    (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
      WHERE o.telephone = c.telephone AND c.telephone <> '' AND o.dot_number <> c.dot_number)
                                                                          AS other_carriers_same_phone,
    (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
      WHERE o.email_address = c.email_address AND c.email_address <> '' AND o.dot_number <> c.dot_number)
                                                                          AS other_carriers_same_email,
    (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o
      WHERE o.phy_street = c.phy_street AND o.phy_city = c.phy_city AND o.phy_state = c.phy_state
        AND CHAR_LENGTH(TRIM(COALESCE(c.phy_street,''))) > 5 AND o.dot_number <> c.dot_number)
                                                                          AS other_carriers_same_address,
    UPPER(CONCAT_WS(' | ', c.phy_street, c.mailing_street)) REGEXP
        'UPS STORE|REGUS|WEWORK|PMB |POSTAL ANNEX|MAIL BOXES ETC|MAILBOX|REGISTERED AGENT|VIRTUAL OFFICE|SUITE #'
                                                                          AS address_looks_virtual,
    c.updated_at                                                          AS fmcsa_synced_at
FROM carrier.carriers c
LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
WHERE c.dot_number = @dot;

-- B3b — who else uses the shared identifier (only when the counts above are > 0).
SELECT o.dot_number, o.legal_name, o.phy_city, o.phy_state
FROM carrier.carriers o
JOIN carrier.carriers c ON c.dot_number = @dot
WHERE o.dot_number <> c.dot_number
  AND (o.telephone = NULLIF(c.telephone, '') OR o.email_address = NULLIF(c.email_address, ''))
LIMIT 50;


-- ----------------------------------------------------------------------------
-- B4 — Identifiers. EIN is not in the FMCSA feed; the column does not exist,
-- so it reports as NA rather than being silently omitted.
-- ----------------------------------------------------------------------------
SET @dot := 1749;

SELECT
    c.legal_name,
    c.dot_number                                                          AS dot,
    (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ')
       FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
    'NA — not carried in the FMCSA feed'                                  AS ein,
    COALESCE(CAST(NULLIF(d.dun_bradstreet_no, 0) AS CHAR), 'NA')          AS duns,
    CONCAT_WS('', d.docket1prefix, NULLIF(d.docket1, 0))                  AS docket_1,
    CONCAT_WS('', d.docket2prefix, NULLIF(d.docket2, 0))                  AS docket_2,
    CONCAT_WS('', d.docket3prefix, NULLIF(d.docket3, 0))                  AS docket_3
FROM carrier.carriers c
LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
WHERE c.dot_number = @dot;


-- ----------------------------------------------------------------------------
-- B5 — Operation classification and cargo carried.
-- Answers the registration fact only — cargo registrations are self-reported
-- to FMCSA and are not a capability endorsement.
-- ----------------------------------------------------------------------------
SET @dot := 1749;

SELECT
    c.legal_name,
    -- classification flags (carrier_census)
    CONCAT_WS(', ',
        IF(ce.authorized_for_hire            IN ('1','X','Y','true'), 'Authorized For Hire', NULL),
        IF(ce.exempt_for_hire                IN ('1','X','Y','true'), 'Exempt For Hire', NULL),
        IF(ce.private_property               IN ('1','X','Y','true'), 'Private Property', NULL),
        IF(ce.private_passenger_business     IN ('1','X','Y','true'), 'Private Passenger (Business)', NULL),
        IF(ce.private_passenger_nonbusiness  IN ('1','X','Y','true'), 'Private Passenger (Non-business)', NULL),
        IF(ce.migrant                        IN ('1','X','Y','true'), 'Migrant', NULL),
        IF(ce.us_mail                        IN ('1','X','Y','true'), 'U.S. Mail', NULL),
        IF(ce.federal_government             IN ('1','X','Y','true'), 'Federal Government', NULL),
        IF(ce.state_government                IN ('1','X','Y','true'), 'State Government', NULL),
        IF(ce.local_government               IN ('1','X','Y','true'), 'Local Government', NULL),
        IF(ce.indian_tribe                   IN ('1','X','Y','true'), 'Indian Tribe', NULL),
        NULLIF(ce.op_other, '')
    )                                                                     AS classifications,

    -- cargo registered (carrier_details.crgo_*)
    CONCAT_WS(', ',
        IF(d.crgo_genfreight  = 'X', 'General Freight', NULL),
        IF(d.crgo_household   = 'X', 'Household Goods', NULL),
        IF(d.crgo_metalsheet  = 'X', 'Metal: Sheets/Coils/Rolls', NULL),
        IF(d.crgo_motoveh     = 'X', 'Motor Vehicles', NULL),
        IF(d.crgo_drivetow    = 'X', 'Drive/Tow Away', NULL),
        IF(d.crgo_logpole     = 'X', 'Logs/Poles/Beams/Lumber', NULL),
        IF(d.crgo_bldgmat     = 'X', 'Building Materials', NULL),
        IF(d.crgo_mobilehome  = 'X', 'Mobile Homes', NULL),
        IF(d.crgo_machlrg     = 'X', 'Machinery/Large Objects', NULL),
        IF(d.crgo_produce     = 'X', 'Fresh Produce', NULL),
        IF(d.crgo_liqgas      = 'X', 'Liquids/Gases', NULL),
        IF(d.crgo_intermodal  = 'X', 'Intermodal Containers', NULL),
        IF(d.crgo_oilfield    = 'X', 'Oilfield Equipment', NULL),
        IF(d.crgo_livestock   = 'X', 'Livestock', NULL),
        IF(d.crgo_grainfeed   = 'X', 'Grain/Feed/Hay', NULL),
        IF(d.crgo_coalcoke    = 'X', 'Coal/Coke', NULL),
        IF(d.crgo_meat        = 'X', 'Meat', NULL),
        IF(d.crgo_garbage     = 'X', 'Garbage/Refuse', NULL),
        IF(d.crgo_usmail      = 'X', 'U.S. Mail', NULL),
        IF(d.crgo_chem        = 'X', 'Chemicals', NULL),
        IF(d.crgo_drybulk     = 'X', 'Commodities Dry Bulk', NULL),
        IF(d.crgo_coldfood    = 'X', 'Refrigerated Food', NULL),
        IF(d.crgo_beverages   = 'X', 'Beverages', NULL),
        IF(d.crgo_paperprod   = 'X', 'Paper Products', NULL),
        IF(d.crgo_utility     = 'X', 'Utility', NULL),
        IF(d.crgo_farmsupp    = 'X', 'Farm Supplies', NULL),
        IF(d.crgo_construct   = 'X', 'Construction', NULL),
        IF(d.crgo_waterwell   = 'X', 'Water Well', NULL)
    )                                                                     AS cargo_registered,
    c.hm_flag                                                             AS hazmat_flag,
    a.hhg_chk                                                             AS household_goods_authority,
    a.passenger_chk                                                       AS passenger_authority,
    'Cargo registrations are self-reported to FMCSA.'                     AS caveat
FROM carrier.carriers c
LEFT JOIN carrier.carrier_details    d  ON d.dot_number  = c.dot_number
LEFT JOIN carrier.carrier_census     ce ON ce.dot_number = c.dot_number
LEFT JOIN carrier.carrier_authorities a ON a.dot_number  = c.dot_number
WHERE c.dot_number = @dot;

-- B5b — "can they haul X?" as a direct yes/no on the registration, one commodity.
SET @cargo_column := 'crgo_grainfeed';
SELECT d.dot_number,
       @cargo_column                                       AS commodity,
       IF(JSON_UNQUOTE(JSON_EXTRACT(JSON_OBJECT(
           'crgo_genfreight', d.crgo_genfreight, 'crgo_grainfeed', d.crgo_grainfeed,
           'crgo_bldgmat',    d.crgo_bldgmat,    'crgo_machlrg',   d.crgo_machlrg,
           'crgo_produce',    d.crgo_produce,    'crgo_livestock', d.crgo_livestock
           /* add the rest as needed */
       ), CONCAT('$.', @cargo_column))) = 'X', 'Registered', 'Not registered') AS registration_state
FROM carrier.carrier_details d
WHERE d.dot_number = @dot;


-- ----------------------------------------------------------------------------
-- B6 — DBA / legal entity mapping. Answers "is X the same as Y?".
-- ----------------------------------------------------------------------------
SET @dot := 1749;

SELECT
    c.dot_number,
    c.legal_name,
    c.dba_name,
    a.legal_name                                                          AS legal_name_on_authority,
    a.dba_name                                                            AS dba_name_on_authority,
    a.docket_number,
    d.business_org_desc                                                   AS entity_type
FROM carrier.carriers c
LEFT JOIN carrier.carrier_authorities a ON a.dot_number = c.dot_number
LEFT JOIN carrier.carrier_details     d ON d.dot_number = c.dot_number
WHERE c.dot_number = @dot;

-- B6b — "is <name> the same entity as <other name>?" Both names, side by side,
-- with the DOT that carries each. Same DOT on both rows => same entity.
SET @name_a := 'WARRIOR TRUCKING';
SET @name_b := 'SIKANDER S GILL';

SELECT
    IF(c.legal_name LIKE CONCAT('%', @name_a, '%') OR c.dba_name LIKE CONCAT('%', @name_a, '%'),
       @name_a, @name_b)        AS matched_on,
    c.dot_number, c.legal_name, c.dba_name, c.phy_city, c.phy_state
FROM carrier.carriers c
WHERE c.legal_name LIKE CONCAT('%', @name_a, '%') OR c.dba_name LIKE CONCAT('%', @name_a, '%')
   OR c.legal_name LIKE CONCAT('%', @name_b, '%') OR c.dba_name LIKE CONCAT('%', @name_b, '%')
LIMIT 25;
