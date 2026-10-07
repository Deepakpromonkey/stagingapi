-- DT score, calculated live from the carrier DB (the same model the app runs in PHP).
-- Weights: safety 24, identity 20, insurance 18, authority 12, crash 10, inspection 8, operations 8.
-- A knockout caps the carrier at 18 / grade F / Rejected.
-- Edit the DOT list in the `dots` CTE below; every other reference follows it.

WITH dots AS (
    SELECT c.dot_number, c.id AS carrier_row_id
    FROM carrier.carriers c
    WHERE c.dot_number IN (1749)
),
insp AS (
    SELECT i.dot_number, COUNT(*) AS inspection_rows
    FROM carrier.inspections i
    JOIN dots ON dots.dot_number = i.dot_number
    GROUP BY i.dot_number
),
units AS (
    SELECT u.dot_number, COUNT(DISTINCT u.unit_vin) AS observed_units
    FROM (
        SELECT i.dot_number, i.vin AS unit_vin, i.unit_type_desc AS unit_type
        FROM carrier.inspections i
        JOIN dots ON dots.dot_number = i.dot_number
        WHERE i.vin IS NOT NULL AND i.vin <> ''
        UNION ALL
        SELECT i.dot_number, i.vin2, i.unit_type_desc2
        FROM carrier.inspections i
        JOIN dots ON dots.dot_number = i.dot_number
        WHERE i.vin2 IS NOT NULL AND i.vin2 <> ''
    ) u
    WHERE u.unit_type IN ('TRUCK TRACTOR','STRAIGHT TRUCK','BUS','SCHOOL BUS','MOTOR COACH','PASSENGER VAN','LIMOUSINE')
    GROUP BY u.dot_number
),
crash AS (
    SELECT cr.dot_number,
           COUNT(*)                       AS crashes_total,
           COALESCE(SUM(cr.fatalities),0) AS fatalities,
           COALESCE(SUM(cr.injuries),0)   AS injuries,
           COALESCE(SUM(cr.tow_away = 1),0) AS tow_away
    FROM carrier.crashes cr
    JOIN dots ON dots.dot_number = cr.dot_number
    GROUP BY cr.dot_number
),
auth_age AS (
    SELECT h.dot_number,
           TIMESTAMPDIFF(YEAR, MIN(CASE WHEN UPPER(h.op_auth_type) IN ('MOTOR PROPERTY COMMON CARRIER','COMMON')     THEN h.served_on END), CURDATE()) AS age_common,
           TIMESTAMPDIFF(YEAR, MIN(CASE WHEN UPPER(h.op_auth_type) IN ('MOTOR PROPERTY CONTRACT CARRIER','CONTRACT') THEN h.served_on END), CURDATE()) AS age_contract,
           TIMESTAMPDIFF(YEAR, MIN(CASE WHEN UPPER(h.op_auth_type) IN ('PROPERTY BROKER','BROKER')                   THEN h.served_on END), CURDATE()) AS age_broker
    FROM (
        SELECT ah.dot_number, ah.op_auth_type,
               COALESCE(STR_TO_DATE(NULLIF(TRIM(ah.orig_served_date),''), '%d-%b-%y'),
                        STR_TO_DATE(NULLIF(TRIM(ah.orig_served_date),''), '%Y-%m-%d'),
                        STR_TO_DATE(NULLIF(TRIM(ah.orig_served_date),''), '%m/%d/%Y')) AS served_on
        FROM carrier.carrier_authority_history ah
        JOIN dots ON dots.dot_number = ah.dot_number
        WHERE UPPER(COALESCE(ah.original_action_desc,'')) = 'GRANTED'
    ) h
    WHERE h.served_on IS NOT NULL AND h.served_on <= CURDATE()
    GROUP BY h.dot_number
),
facts AS (
    SELECT
        c.dot_number,
        c.id                                                        AS carrier_row_id,
        c.legal_name,
        c.telephone,
        c.email_address,
        c.phy_street,
        c.phy_city,
        c.mcs150_mileage,
        c.driver_total,
        c.nbr_power_unit                                            AS reported_units,
        YEAR(c.mcs150_date)                                         AS mcs150_year,
        TIMESTAMPDIFF(YEAR, c.add_date, CURDATE())                  AS dot_age,
        d.safety_rating,
        d.status_code,
        d.prior_revoke_flag,
        d.dun_bradstreet_no                                         AS duns_number,
        d.total_drivers,
        UPPER(TRIM(COALESCE(a.common_stat,'')))   IN ('A','ACTIVE') AS common_active,
        UPPER(TRIM(COALESCE(a.contract_stat,''))) IN ('A','ACTIVE') AS contract_active,
        UPPER(TRIM(COALESCE(a.broker_stat,'')))   IN ('A','ACTIVE') AS broker_active,
        UPPER(TRIM(COALESCE(a.common_app_pend,'')))   IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS common_app_pending,
        UPPER(TRIM(COALESCE(a.contract_app_pend,''))) IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS contract_app_pending,
        UPPER(TRIM(COALESCE(a.broker_app_pend,'')))   IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS broker_app_pending,
        UPPER(TRIM(COALESCE(a.common_rev_pend,'')))   IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS common_rev_pending,
        UPPER(TRIM(COALESCE(a.contract_rev_pend,''))) IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS contract_rev_pending,
        UPPER(TRIM(COALESCE(a.broker_rev_pend,'')))   IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS broker_rev_pending,
        UPPER(TRIM(COALESCE(a.cargo_req,''))) IN ('TRUE','Y','YES','X','1','A','ACTIVE')         AS cargo_required,
        UPPER(TRIM(COALESCE(a.bond_req,'')))  IN ('TRUE','Y','YES','X','1','A','ACTIVE')         AS bond_required,
        (TRIM(COALESCE(a.bipd_file,'')) <> '' AND IF(TRIM(a.bipd_file) REGEXP '^[0-9]+([.][0-9]+)?$',
            CAST(a.bipd_file AS DECIMAL(20,4)) > 0,
            UPPER(TRIM(a.bipd_file)) IN ('TRUE','Y','YES','X','1','A','ACTIVE')))                AS bipd_on_file,
        (TRIM(COALESCE(a.cargo_file,'')) <> '' AND IF(TRIM(a.cargo_file) REGEXP '^[0-9]+([.][0-9]+)?$',
            CAST(a.cargo_file AS DECIMAL(20,4)) > 0,
            UPPER(TRIM(a.cargo_file)) IN ('TRUE','Y','YES','X','1','A','ACTIVE')))               AS cargo_on_file,
        (TRIM(COALESCE(a.bond_file,'')) <> '' AND IF(TRIM(a.bond_file) REGEXP '^[0-9]+([.][0-9]+)?$',
            CAST(a.bond_file AS DECIMAL(20,4)) > 0,
            UPPER(TRIM(a.bond_file)) IN ('TRUE','Y','YES','X','1','A','ACTIVE')))                AS bond_on_file,
        CAST(COALESCE(NULLIF(TRIM(a.min_cov_amount),''),'0') AS DECIMAL(20,2))                   AS min_cov_amount,
        CASE WHEN COALESCE(s.unsafe_driv_measure,0) <= 0 THEN 0 WHEN s.unsafe_driv_measure >= 8.00  THEN 90 WHEN s.unsafe_driv_measure >= 2.50 THEN 75 ELSE 50 END AS band_unsafe,
        CASE WHEN COALESCE(s.hos_driv_measure,0)    <= 0 THEN 0 WHEN s.hos_driv_measure    >= 2.80  THEN 90 WHEN s.hos_driv_measure    >= 0.51 THEN 75 ELSE 50 END AS band_hos,
        CASE WHEN COALESCE(s.veh_maint_measure,0)   <= 0 THEN 0 WHEN s.veh_maint_measure   >= 15.00 THEN 90 WHEN s.veh_maint_measure   >= 8.66 THEN 75 WHEN s.veh_maint_measure >= 3.60 THEN 50 ELSE 0 END AS band_maint,
        CASE WHEN COALESCE(s.driv_fit_measure,0)    <= 0 THEN 0 WHEN s.driv_fit_measure    >= 1.79  THEN 90 WHEN s.driv_fit_measure    >= 0.12 THEN 75 ELSE 50 END AS band_fitness,
        CASE WHEN COALESCE(s.contr_subst_measure,0) <= 0 THEN 0 ELSE 90 END                      AS band_subst,
        UPPER(TRIM(COALESCE(s.unsafe_driv_ac,''))) IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS ac_unsafe,
        UPPER(TRIM(COALESCE(s.hos_driv_ac,'')))    IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS ac_hos,
        UPPER(TRIM(COALESCE(s.veh_maint_ac,'')))   IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS ac_maint,
        UPPER(TRIM(COALESCE(s.driv_fit_ac,'')))    IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS ac_fitness,
        UPPER(TRIM(COALESCE(s.contr_subst_ac,''))) IN ('TRUE','Y','YES','X','1','A','ACTIVE') AS ac_subst,
        IF(COALESCE(s.vehicle_insp_total,0) > 0, ROUND(s.vehicle_oos_insp_total / s.vehicle_insp_total * 100, 2), NULL) AS vehicle_oos_pct,
        IF(COALESCE(s.driver_insp_total,0)  > 0, ROUND(s.driver_oos_insp_total  / s.driver_insp_total  * 100, 2), NULL) AS driver_oos_pct,
        COALESCE(insp.inspection_rows, 0)                                                        AS inspection_rows,
        COALESCE(NULLIF(s.insp_total,0), insp.inspection_rows, 0)                                AS insp_total,
        IF(COALESCE(NULLIF(s.insp_total,0), insp.inspection_rows, 0) > 0,
           ROUND((COALESCE(s.unsafe_driv_insp_w_viol,0) + COALESCE(s.hos_driv_insp_w_viol,0)
                + COALESCE(s.driv_fit_insp_w_viol,0)    + COALESCE(s.contr_subst_insp_w_viol,0)
                + COALESCE(s.veh_maint_insp_w_viol,0))
               / COALESCE(NULLIF(s.insp_total,0), insp.inspection_rows), 3), NULL)               AS violation_rate,
        COALESCE(units.observed_units, 0)                                                        AS observed_units,
        COALESCE(crash.crashes_total, 0)                                                         AS crashes_total,
        COALESCE(crash.fatalities, 0)                                                            AS crash_fatalities,
        COALESCE(crash.injuries, 0)                                                              AS crash_injuries,
        COALESCE(crash.tow_away, 0)                                                              AS crashes_tow_away,
        IF(COALESCE(c.nbr_power_unit,0) > 0, ROUND(COALESCE(crash.crashes_total,0) / c.nbr_power_unit, 3), NULL) AS crashes_per_unit,
        NULLIF(GREATEST(COALESCE(NULLIF(ag.age_common,0),0),
                        COALESCE(NULLIF(ag.age_contract,0),0),
                        COALESCE(NULLIF(ag.age_broker,0),0)), 0)                                 AS oldest_authority_age,
        (SELECT COUNT(*) FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number)             AS active_filings,
        (SELECT COUNT(*) FROM carrier.insurance_filings_pending i WHERE i.dot_number = c.dot_number)     AS pending_filings,
        (SELECT COUNT(*) FROM carrier.insurance_filings_pending i WHERE i.dot_number = c.dot_number AND COALESCE(TRIM(i.rej_date),'') <> '') AS rejected_filings,
        (SELECT COUNT(*) FROM carrier.insurance_filings_history i WHERE i.dot_number = c.dot_number)     AS history_filings,
        (SELECT COUNT(DISTINCT i.name_company) FROM carrier.insurance_filings_history i WHERE i.dot_number = c.dot_number AND COALESCE(TRIM(i.name_company),'') <> '') AS insurer_changes,
        (SELECT COUNT(DISTINCT i.policy_no)    FROM carrier.insurance_filings_history i WHERE i.dot_number = c.dot_number AND COALESCE(TRIM(i.policy_no),'')    <> '') AS policy_changes,
        EXISTS (SELECT 1 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number
                 AND (UPPER(TRIM(COALESCE(i.ins_form_code,''))) IN ('91','91X') OR UPPER(COALESCE(i.ins_type_desc,'')) LIKE 'BIPD%')
                 AND (COALESCE(TRIM(i.cancl_effective_date),'') = '' OR i.cancl_effective_date > CURDATE())) AS has_bipd_filing,
        EXISTS (SELECT 1 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number
                 AND (UPPER(TRIM(COALESCE(i.ins_form_code,''))) = '34' OR UPPER(COALESCE(i.ins_type_desc,'')) LIKE '%CARGO%')
                 AND (COALESCE(TRIM(i.cancl_effective_date),'') = '' OR i.cancl_effective_date > CURDATE())) AS has_cargo_filing,
        EXISTS (SELECT 1 FROM carrier.insurance_filings i WHERE i.dot_number = c.dot_number
                 AND (UPPER(TRIM(COALESCE(i.ins_form_code,''))) IN ('84','85')
                      OR UPPER(COALESCE(i.ins_type_desc,'')) LIKE '%SURETY%'
                      OR UPPER(COALESCE(i.ins_type_desc,'')) LIKE '%BOND%'
                      OR UPPER(COALESCE(i.ins_type_desc,'')) LIKE '%TRUST FUND%')
                 AND (COALESCE(TRIM(i.cancl_effective_date),'') = '' OR i.cancl_effective_date > CURDATE())) AS has_bond_filing,
        (SELECT COUNT(*) FROM carrier.carrier_oos_orders o WHERE o.dot_number = c.dot_number AND UPPER(o.status) = 'ACTIVE' AND COALESCE(TRIM(o.rescind_date),'') = '') AS active_oos,
        (SELECT COUNT(*) FROM carrier.carrier_authority_orders o WHERE o.dot_number = c.dot_number) AS authority_orders,
        (SELECT COUNT(*) FROM carrier.carrier_authority_orders o WHERE o.dot_number = c.dot_number AND UPPER(COALESCE(o.order2_type_desc,'')) LIKE '%SUSPEND%') AS suspension_orders,
        (SELECT COUNT(*) FROM carrier.carrier_authority_history h WHERE h.dot_number = c.dot_number AND UPPER(COALESCE(h.disp_action_desc,'')) LIKE '%REVOK%') AS revocation_count
    FROM carrier.carriers c
    JOIN dots ON dots.dot_number = c.dot_number
    LEFT JOIN carrier.carrier_details d
           ON d.id = (SELECT x.id FROM carrier.carrier_details x
                       WHERE x.dot_number = c.dot_number ORDER BY x.id DESC LIMIT 1)
    LEFT JOIN carrier.carrier_authorities a
           ON a.id = (SELECT x.id FROM carrier.carrier_authorities x
                       WHERE x.dot_number = c.dot_number ORDER BY x.id DESC LIMIT 1)
    LEFT JOIN carrier.sms_measures s ON s.dot_number = c.dot_number
    LEFT JOIN insp     ON insp.dot_number     = c.dot_number
    LEFT JOIN units    ON units.dot_number    = c.dot_number
    LEFT JOIN crash    ON crash.dot_number    = c.dot_number
    LEFT JOIN auth_age ag ON ag.dot_number    = c.dot_number
),
pillars AS (
    SELECT
        f.*,
        LEAST(24, GREATEST(0, ROUND(24
          + CASE UPPER(COALESCE(f.safety_rating,''))
                 WHEN 'U' THEN -10 WHEN 'UNSATISFACTORY' THEN -10
                 WHEN 'C' THEN -5  WHEN 'CONDITIONAL'    THEN -5
                 WHEN 'S' THEN 0   WHEN 'SATISFACTORY'   THEN 0
                 ELSE -2 END
          + CASE WHEN f.band_unsafe  >= 90 THEN -5 WHEN f.band_unsafe  >= 75 THEN -4 WHEN f.band_unsafe  >= 50 THEN -2 ELSE 0 END
          + CASE WHEN f.band_hos     >= 90 THEN -4 WHEN f.band_hos     >= 75 THEN -3 WHEN f.band_hos     >= 50 THEN -2 ELSE 0 END
          + CASE WHEN f.band_maint   >= 90 THEN -5 WHEN f.band_maint   >= 75 THEN -4 WHEN f.band_maint   >= 50 THEN -2 ELSE 0 END
          + CASE WHEN f.band_fitness >= 90 THEN -3 WHEN f.band_fitness >= 75 THEN -2 ELSE 0 END
          + CASE WHEN f.band_subst   >= 90 THEN -5 WHEN f.band_subst   >= 75 THEN -3 ELSE 0 END
          + CASE WHEN f.vehicle_oos_pct >= 30 THEN -3 WHEN f.vehicle_oos_pct >= 20 THEN -2 WHEN f.vehicle_oos_pct >= 10 THEN -1 ELSE 0 END
          + CASE WHEN f.driver_oos_pct  >= 15 THEN -3 WHEN f.driver_oos_pct  >= 10 THEN -2 WHEN f.driver_oos_pct  >= 5  THEN -1 ELSE 0 END
          - 2 * ((f.band_unsafe >= 90) + (f.band_hos >= 90) + (f.band_maint >= 90) + (f.band_fitness >= 90) + (f.band_subst >= 90))
          - (f.ac_unsafe + f.ac_hos + f.ac_maint + f.ac_fitness + f.ac_subst)
          + CASE WHEN f.inspection_rows = 0 THEN -4 WHEN f.inspection_rows < 5 THEN -2 ELSE 0 END
        ))) AS safety_score,
        LEAST(20, GREATEST(0, ROUND(20
          + IF(UPPER(COALESCE(f.prior_revoke_flag,'')) = 'Y', -8, 0)
          + IF(COALESCE(TRIM(f.duns_number),'') = '', -2, 0)
          + CASE WHEN COALESCE(TRIM(f.email_address),'') = '' THEN -2
                 WHEN LOWER(SUBSTRING_INDEX(f.email_address,'@',-1)) IN
                      ('gmail.com','yahoo.com','hotmail.com','outlook.com','icloud.com',
                       'aol.com','live.com','msn.com','protonmail.com') THEN -2
                 ELSE 0 END
          + IF(COALESCE(TRIM(f.telephone),'') = '', -2, 0)
          + IF(COALESCE(TRIM(f.phy_street),'') = '' OR COALESCE(TRIM(f.phy_city),'') = '', -2, 0)
        ))) AS identity_score,
        LEAST(18, GREATEST(0, ROUND(18
          + IF(f.active_filings = 0, -10, 0)
          + IF(NOT f.bipd_on_file  AND NOT f.has_bipd_filing, -5, 0)
          + IF(f.cargo_required AND NOT f.cargo_on_file AND NOT f.has_cargo_filing, -5, 0)
          + IF(f.bond_required  AND NOT f.bond_on_file  AND NOT f.has_bond_filing, -4, 0)
          + CASE WHEN f.min_cov_amount = 0 THEN -3 WHEN f.min_cov_amount < 750000 THEN -2 WHEN f.min_cov_amount >= 1000000 THEN 1 ELSE 0 END
          + IF(f.pending_filings > 0, -2, 0)
          + CASE WHEN f.rejected_filings >= 3 THEN -4 WHEN f.rejected_filings >= 1 THEN -2 ELSE 0 END
          + CASE WHEN f.insurer_changes  >= 8 THEN -4 WHEN f.insurer_changes  >= 5 THEN -2 ELSE 0 END
          + CASE WHEN f.policy_changes  >= 10 THEN -4 WHEN f.policy_changes   >= 5 THEN -2 ELSE 0 END
          + IF(f.history_filings = 0, -2, 0)
        ))) AS insurance_score,
        LEAST(12, GREATEST(0, ROUND(12
          + IF(f.common_active, 1, -2)
          + IF(f.contract_active, 1, -2)
          + IF(f.broker_active, 1, 0)
          - (f.common_app_pending + f.contract_app_pending + f.broker_app_pending)
          - 3 * (f.common_rev_pending + f.contract_rev_pending + f.broker_rev_pending)
          + CASE WHEN f.revocation_count  >= 5 THEN -5 WHEN f.revocation_count  >= 3 THEN -3 WHEN f.revocation_count >= 1 THEN -1 ELSE 0 END
          + CASE WHEN f.suspension_orders >= 3 THEN -3 WHEN f.suspension_orders >= 1 THEN -1 ELSE 0 END
          + CASE WHEN f.oldest_authority_age IS NULL THEN 0
                 WHEN f.oldest_authority_age >= 15 THEN 2
                 WHEN f.oldest_authority_age >= 10 THEN 1
                 WHEN f.oldest_authority_age < 2  THEN -2
                 ELSE 0 END
          + CASE WHEN f.authority_orders >= 10 THEN -2 WHEN f.authority_orders >= 5 THEN -1 ELSE 0 END
        ))) AS authority_score,
        LEAST(10, GREATEST(0, ROUND(10
          + CASE WHEN f.crash_fatalities >= 3 THEN -8 WHEN f.crash_fatalities >= 1 THEN -5 ELSE 0 END
          + CASE WHEN f.crash_injuries  >= 10 THEN -3 WHEN f.crash_injuries  >= 5 THEN -2 WHEN f.crash_injuries  >= 1 THEN -1 ELSE 0 END
          + CASE WHEN f.crashes_tow_away>= 10 THEN -3 WHEN f.crashes_tow_away>= 5 THEN -2 WHEN f.crashes_tow_away>= 1 THEN -1 ELSE 0 END
          + CASE WHEN f.crashes_total   >= 20 THEN -3 WHEN f.crashes_total   >= 10 THEN -2 WHEN f.crashes_total  >= 5 THEN -1 ELSE 0 END
          + CASE WHEN f.crashes_per_unit IS NULL THEN 0
                 WHEN f.crashes_per_unit >= 1.0  THEN -3
                 WHEN f.crashes_per_unit >= 0.5  THEN -2
                 WHEN f.crashes_per_unit >= 0.25 THEN -1
                 ELSE 0 END
        ))) AS crash_score,
        LEAST(8, GREATEST(0, ROUND(8
          + CASE WHEN f.insp_total = 0 THEN -4 WHEN f.insp_total < 5 THEN -2 ELSE 0 END
          + CASE WHEN f.violation_rate IS NULL THEN 0
                 WHEN f.violation_rate >= 0.75 THEN -3
                 WHEN f.violation_rate >= 0.50 THEN -2
                 WHEN f.violation_rate >= 0.25 THEN -1
                 ELSE 0 END
          + CASE WHEN f.vehicle_oos_pct >= 30 THEN -2 WHEN f.vehicle_oos_pct >= 20 THEN -1 ELSE 0 END
          + CASE WHEN f.driver_oos_pct  >= 15 THEN -2 WHEN f.driver_oos_pct  >= 10 THEN -1 ELSE 0 END
        ))) AS inspection_score,
        LEAST(8, GREATEST(0, ROUND(8
          + CASE WHEN f.dot_age IS NULL THEN -1
                 WHEN f.dot_age < 2   THEN -3
                 WHEN f.dot_age < 5   THEN -1
                 WHEN f.dot_age >= 10 THEN 1
                 ELSE 0 END
          + CASE WHEN f.mcs150_year IS NULL THEN -2
                 WHEN YEAR(CURDATE()) - f.mcs150_year > 2 THEN -2
                 ELSE 0 END
          + IF(f.reported_units > 0 AND f.observed_units = 0, -2, 0)
          + IF(COALESCE(f.mcs150_mileage,0) <= 0, -1, 0)
          + IF(COALESCE(f.total_drivers, f.driver_total, 0) <= 0, -1, 0)
        ))) AS operations_score,
        ((NOT f.common_active AND NOT f.contract_active)
         OR UPPER(COALESCE(f.status_code,'')) = 'I'
         OR UPPER(COALESCE(f.safety_rating,'')) = 'U'
         OR (NOT f.bipd_on_file AND NOT f.has_bipd_filing)
         OR (f.cargo_required AND NOT f.cargo_on_file AND NOT f.has_cargo_filing)
         OR (f.bond_required  AND NOT f.bond_on_file  AND NOT f.has_bond_filing)
         OR f.active_oos > 0)                                        AS knockout
    FROM facts f
),
totals AS (
    SELECT p.*,
           p.safety_score + p.identity_score + p.insurance_score + p.authority_score
         + p.crash_score  + p.inspection_score + p.operations_score AS raw_total
    FROM pillars p
)
SELECT
    t.dot_number,
    t.carrier_row_id,
    t.legal_name,
    IF(t.knockout, 18, t.raw_total)                                  AS dt_score,
    CASE WHEN t.knockout THEN 'F'
         WHEN t.raw_total >= 90 THEN 'A'
         WHEN t.raw_total >= 80 THEN 'B'
         WHEN t.raw_total >= 70 THEN 'C'
         WHEN t.raw_total >= 60 THEN 'D'
         ELSE 'F' END                                                AS dt_grade,
    CASE WHEN t.knockout THEN 'Rejected'
         WHEN t.raw_total >= 80 THEN 'Approved'
         WHEN t.raw_total >= 60 THEN 'Review'
         ELSE 'High Risk' END                                        AS dt_band,
    t.knockout,
    CONCAT_WS('; ',
        IF(NOT t.common_active AND NOT t.contract_active, 'AUTHORITY_INACTIVE', NULL),
        IF(UPPER(COALESCE(t.status_code,'')) = 'I', 'DOT_INACTIVE', NULL),
        IF(UPPER(COALESCE(t.safety_rating,'')) = 'U', 'UNSATISFACTORY_RATING', NULL),
        IF(NOT t.bipd_on_file AND NOT t.has_bipd_filing, 'NO_BIPD', NULL),
        IF(t.cargo_required AND NOT t.cargo_on_file AND NOT t.has_cargo_filing, 'NO_CARGO_INSURANCE', NULL),
        IF(t.bond_required  AND NOT t.bond_on_file  AND NOT t.has_bond_filing, 'NO_BOND', NULL),
        IF(t.active_oos > 0, 'OUT_OF_SERVICE', NULL)
    )                                                                AS knockout_reasons,
    t.safety_score,
    t.identity_score,
    t.insurance_score,
    t.authority_score,
    t.crash_score,
    t.inspection_score,
    t.operations_score,
    t.raw_total                                                      AS score_before_knockout,
    t.safety_rating,
    t.vehicle_oos_pct,
    t.driver_oos_pct,
    t.insp_total,
    t.violation_rate,
    t.crashes_total,
    t.dot_age,
    t.oldest_authority_age,
    t.observed_units,
    t.reported_units
FROM totals t
ORDER BY dt_score;
