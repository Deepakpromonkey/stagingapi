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
