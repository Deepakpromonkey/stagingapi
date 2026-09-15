-- B3  Contact information and identity uniqueness  (run on the carrier DB)
SET @dot := 1749;

SELECT
    b.carrier_row_id,
    b.dot_number,
    b.mc_numbers,
    b.legal_name,
    b.dba_name,
    b.telephone                                                     AS dispatch_phone,
    b.fax,
    b.email_address                                                 AS official_email,
    SUBSTRING_INDEX(b.email_address, '@', -1)                       AS email_domain,
    IF(b.email_address LIKE '%@%', CONCAT('https://', SUBSTRING_INDEX(b.email_address, '@', -1)), NULL) AS web_presence,
    CONCAT_WS(', ', b.phy_street, b.phy_city, b.phy_state, b.phy_zip)       AS physical_address,
    CONCAT_WS(', ', b.mailing_street, b.mailing_city, b.mailing_state, b.mailing_zip) AS mailing_address,
    b.phone_shared_with,
    b.email_shared_with,
    b.address_shared_with,
    b.phone_shared_with = 0                                         AS phone_unique,
    b.email_shared_with = 0                                         AS email_unique,
    b.address_shared_with = 0                                       AS address_unique,
    UPPER(CONCAT_WS(' | ', b.phy_street, b.mailing_street)) REGEXP
        'UPS STORE|REGUS|WEWORK|PMB |POSTAL ANNEX|MAIL BOXES ETC|MAILBOX|REGISTERED AGENT|VIRTUAL OFFICE|SUITE #' AS virtual_address,
    b.coi_producer_email,
    b.coi_producer_name,
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
            c.dba_name,
            c.telephone,
            c.fax,
            c.email_address,
            c.phy_street, c.phy_city, c.phy_state, c.phy_zip,
            c.mailing_street, c.mailing_city, c.mailing_state, c.mailing_zip,
            (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ') FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
            (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o WHERE o.telephone = c.telephone AND c.telephone <> '' AND o.dot_number <> c.dot_number) AS phone_shared_with,
            (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o WHERE o.email_address = c.email_address AND c.email_address <> '' AND o.dot_number <> c.dot_number) AS email_shared_with,
            (SELECT COUNT(DISTINCT o.dot_number) FROM carrier.carriers o WHERE o.phy_street = c.phy_street AND o.phy_city = c.phy_city AND o.phy_state = c.phy_state AND CHAR_LENGTH(TRIM(COALESCE(c.phy_street,''))) > 5 AND o.dot_number <> c.dot_number) AS address_shared_with,
            cx.status                                               AS coi_extraction_status,
            cx.extracted_at                                         AS coi_extracted_at,
            IF(JSON_VALID(cx.extracted_json), COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.producer_email'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.agency_email'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.agent_email'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.email'),'$[0]'))
            ), NULL)                                                AS coi_producer_email,
            IF(JSON_VALID(cx.extracted_json), COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.producer_name'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.producer'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.agency'),'$[0]'))
            ), NULL)                                                AS coi_producer_name,
            IF(JSON_VALID(cx.extracted_json), COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiration_date'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.policy_expiration_date'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.policy_expiration'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiry_date'),'$[0]')),
                JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(cx.extracted_json,'$**.expiration'),'$[0]'))
            ), NULL)                                                AS coi_expiry_raw
        FROM carrier.carriers c
        LEFT JOIN carrier.coi_document_extractions cx
               ON cx.id = (SELECT e.id FROM carrier.coi_document_extractions e
                            WHERE e.dot_number = c.dot_number
                            ORDER BY e.extracted_at DESC, e.id DESC LIMIT 1)
        WHERE c.dot_number = @dot
    ) r
) b;

-- B3b  DT score  (run on the carrier DB)
-- The DT score is calculated live from FMCSA data every time it is shown; it is
-- not stored. search_histories.dt_score is only a snapshot written when someone
-- opened a profile, so it is missing for any carrier nobody has opened yet.
-- To get the score, run dt-score.sql and put the same @dot in its `dots` CTE.
