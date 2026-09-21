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
