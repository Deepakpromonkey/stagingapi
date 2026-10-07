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
