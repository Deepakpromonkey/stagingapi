/*
| Creates an FMCSA carrier profile for "PROMONKEY LOGISTICS LLC".
|
| RUN THIS ON THE EXTERNAL CARRIER DATABASE, NOT THE APP DATABASE:
|     mysql -h 18.222.238.176 -u laravel -p carrier < this-file.sql
|
| Everything the app reads (`carriers`, `carrier_details`, `carrier_census`,
| `carrier_authorities`, `sms_measures`, `insurance_filings`, `inspections`,
| `crashes`, `carrier_contacts`) is a VIEW. You cannot insert into a view, so
| each block below writes to the base table the view sits on:
|
|     carriers, carrier_details   ->  company_census_file
|     carrier_census              ->  sms_input_motor_carrier_census_information
|     carrier_authorities         ->  carrier_all_with_history
|     sms_measures                ->  sms_ab_passproperty
|     insurance_filings           ->  inshist_all_with_history
|     inspections                 ->  sms_input_inspection
|     crashes                     ->  sms_input_crash
|     carrier_contacts            ->  boc3_all_with_history
|
| These base tables are reloaded by hand from the CSV extracts in
| dot-extractor/data (see _load_manifest). A reload of company_census_file will
| wipe this row -- re-run the file if that happens.
|
| _row_id is auto_increment everywhere, and dot_int is a GENERATED column --
| neither is ever inserted.
*/

-- 9500001 sits in an empty band of the census file. Confirm it is still free
-- before you run the rest:
--     SELECT COUNT(*) FROM company_census_file WHERE dot_number = 9500001;

SET @dot        := 9500001;
SET @dot_padded := '09500001';        -- the *_all_with_history zero-padded form
SET @docket     := 'MC1099231';
SET @name       := 'PROMONKEY LOGISTICS LLC';
SET @dba        := 'PROMONKEY LOGISTICS';
SET @email      := 'deepak@promonkey.tech';
SET @phone      := '+918076734039';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. company_census_file -- the profile itself.
--    Backs BOTH the `carriers` view (search, name, address, contact) and the
--    `carrier_details` view (fleet, cargo, safety rating, dockets).
--
--    Date formats are the feed's, not MySQL's: mcs150_date is a varchar
--    'YYYYMMDD HHMM', and add_date / review_date / safety_rating_date are
--    bigints of YYYYMMDD. The view parses them with STR_TO_DATE.
-- ---------------------------------------------------------------------------

INSERT INTO company_census_file
    (dot_number, status_code, legal_name, dba_name, carrier_operation,
     phy_street, phy_city, phy_state, phy_zip, phy_country, phy_cnty,
     carrier_mailing_street, carrier_mailing_city, carrier_mailing_state,
     carrier_mailing_zip, carrier_mailing_country,
     phone, fax, cell_phone, email_address,
     mcs150_date, mcs150_mileage, mcs150_mileage_year, add_date,
     power_units, truck_units, total_drivers, total_cdl, driver_inter_total,
     owntruck, owntract, owntrail,
     interstate_beyond_100_miles, interstate_within_100_miles,
     hm_ind, business_org_id, business_org_desc, fleetsize,
     company_officer_1, classdef,
     crgo_genfreight, crgo_coldfood, crgo_paperprod,
     docket1prefix, docket1, docket1_status_code,
     review_type, review_date, safety_rating, safety_rating_date,
     recordable_crash_rate, undeliv_phy, _source_file)
VALUES
    (@dot, 'A', @name, @dba, 'A',
     '4200 DIPLOMACY RD', 'DALLAS', 'TX', '75261', 'US', 'DALLAS',
     '4200 DIPLOMACY RD', 'DALLAS', 'TX',
     '75261', 'US',
     @phone, NULL, NULL, @email,
     '20260115 0930', 485000, 2025, 20190412,
     12, 12, 14, 14, 14,
     4, 8, 18,
     14, 0,
     0, 2, 'LLC', 'B',
     'DEEPAK SHARMA', 'AUTH FOR HIRE',
     'X', 'X', 'X',
     'MC', 1099231, 'A',
     'C', 20220310, 'S', 20220415,
     0.000, 'N', 'seed-promonkey');

-- ---------------------------------------------------------------------------
-- 2. sms_input_motor_carrier_census_information -- the `carrier_census` view.
--    The MCS-150 operation-classification flags. Only ~700k of the 4M carriers
--    have a row here, so a null reads as "not set" -- but without it the
--    "Authorized For Hire" style flags on the profile stay blank.
--    Dates here ARE real DATE columns, unlike block 1.
-- ---------------------------------------------------------------------------

INSERT INTO sms_input_motor_carrier_census_information
    (dot_number, legal_name, dba_name, carrier_operation, hm_flag, pc_flag,
     phy_street, phy_city, phy_state, phy_zip, phy_country,
     mailing_street, mailing_city, mailing_state, mailing_zip, mailing_country,
     telephone, fax, email_address,
     mcs150_date, mcs150_mileage, mcs150_mileage_year, add_date, oic_state,
     nbr_power_unit, driver_total, recent_mileage, recent_mileage_year, vmt_source_id,
     private_only, authorized_for_hire, exempt_for_hire, private_property,
     private_passenger_business, private_passenger_nonbusiness, migrant, us_mail,
     federal_government, state_government, local_government, indian_tribe, op_other,
     _source_file)
VALUES
    (@dot, @name, @dba, 'A', 0, 0,
     '4200 DIPLOMACY RD', 'DALLAS', 'TX', '75261', 'US',
     '4200 DIPLOMACY RD', 'DALLAS', 'TX', '75261', 'US',
     @phone, NULL, @email,
     '2026-01-15', 485000, 2025, '2019-04-12', 'TX',
     12, 14, 485000, 2025, 1,
     0, 1, 0, 0,
     0, 0, 0, 0,
     0, 0, 0, 0, NULL,
     'seed-promonkey');

-- ---------------------------------------------------------------------------
-- 3. carrier_all_with_history -- the `carrier_authorities` view.
--    This is where the MC number comes from: the profile reads mc_number
--    straight off authority->docket_number. Without this row the carrier shows
--    no MC and no operating authority.
--    dot_number is the zero-padded varchar; dot_int derives from it.
-- ---------------------------------------------------------------------------

INSERT INTO carrier_all_with_history
    (docket_number, dot_number, mx_type, rfc_number,
     common_stat, contract_stat, broker_stat,
     common_app_pend, contract_app_pend, broker_app_pend,
     common_rev_pend, contract_rev_pend, broker_rev_pend,
     property_chk, passenger_chk, hhg_chk, private_auth_chk, enterprise_chk,
     min_cov_amount, cargo_req, bond_req, bipd_file, cargo_file, bond_file,
     undeliverable_mail, dba_name, legal_name,
     bus_street_po, bus_city, bus_state_code, bus_ctry_code, bus_zip_code, bus_telno,
     _source_file)
VALUES
    (@docket, @dot_padded, NULL, NULL,
     'A', 'N', 'N',
     0, 0, 0,
     0, 0, 0,
     1, 0, 0, 0, 0,
     '00750', 1, 0, '00750', 1, 0,
     0, @dba, @name,
     '4200 DIPLOMACY RD', 'DALLAS', 'TX', 'US', '75261', @phone,
     'seed-promonkey');

-- ---------------------------------------------------------------------------
-- 4. sms_ab_passproperty -- the `sms_measures` view.
--    The BASIC measures. CarrierProfileService builds its risk score out of
--    unsafe_driv_measure + hos_driv_measure + veh_maint_measure, and the
--    driver/vehicle OOS percentages out of the four *_insp_total columns, so
--    without this row the whole safety panel is zeroed.
--    These numbers describe a clean, low-risk carrier.
-- ---------------------------------------------------------------------------

INSERT INTO sms_ab_passproperty
    (dot_number, insp_total, driver_insp_total, driver_oos_insp_total,
     vehicle_insp_total, vehicle_oos_insp_total,
     unsafe_driv_insp_w_viol, unsafe_driv_measure, unsafe_driv_ac,
     hos_driv_insp_w_viol, hos_driv_measure, hos_driv_ac,
     driv_fit_insp_w_viol, driv_fit_measure, driv_fit_ac,
     contr_subst_insp_w_viol, contr_subst_measure, contr_subst_ac,
     veh_maint_insp_w_viol, veh_maint_measure, veh_maint_ac,
     _source_file)
VALUES
    (@dot, 24, 18, 1,
     20, 3,
     2, 0.85, 0,
     1, 0.42, 0,
     0, 0.00, 0,
     0, 0.00, 0,
     3, 1.90, 0,
     'seed-promonkey');

-- ---------------------------------------------------------------------------
-- 5. inshist_all_with_history -- the `insurance_filings` view.
--    Amounts are in THOUSANDS: the app multiplies by 1000, so max_cov_amount
--    1000 renders as $1,000,000. The BIPD / Cargo split is matched on
--    ins_type_desc containing "BIPD" / "Cargo", so those strings matter.
--    cancl_effective_date NULL = still in force.
-- ---------------------------------------------------------------------------

INSERT INTO inshist_all_with_history
    (docket_number, dot_number, ins_form_code, cancl_method_gen, ins_cancl_form,
     ins_type_ind, ins_type_desc, policy_no, min_cov_amount, ins_class_code,
     effective_date, underl_lim_amount, max_cov_amount,
     cancl_effective_date, cancl_method, inser_branch, name_company, _source_file)
VALUES
    (@docket, @dot_padded, '91X', NULL, NULL,
     NULL, 'BIPD/Primary', 'PMK-BIPD-778210', 750, 'P',
     '2026-02-01', 0, 1000,
     NULL, NULL, '01', 'GREAT WEST CASUALTY COMPANY', 'seed-promonkey'),
    (@docket, @dot_padded, '91X', NULL, NULL,
     NULL, 'Cargo', 'PMK-CARGO-778211', 100, 'C',
     '2026-02-01', 0, 100,
     NULL, NULL, '01', 'GREAT WEST CASUALTY COMPANY', 'seed-promonkey');

-- ---------------------------------------------------------------------------
-- 6. sms_input_inspection -- the `inspections` view.
--    Feeds the observed-equipment counts (distinct VINs by unit_type_desc),
--    the per-state lane breakdown (county_code_state) and the inspection
--    totals on the profile. unit_type_desc must use the feed's own vocabulary
--    ('TRUCK TRACTOR', 'SEMI-TRAILER') or the VIN counts skip the row.
-- ---------------------------------------------------------------------------

INSERT INTO sms_input_inspection
    (unique_id, report_number, report_state, dot_number, insp_date, insp_level_id,
     county_code_state, time_weight, driver_oos_total, vehicle_oos_total,
     total_hazmat_sent, oos_total, hazmat_oos_total, hazmat_placard_req,
     unit_type_desc, unit_make, unit_license, unit_license_state, vin,
     unit_type_desc2, unit_make2, unit_license2, unit_license_state2, vin2,
     unsafe_insp, fatigued_insp, dr_fitness_insp, subt_alcohol_insp, vh_maint_insp, hm_insp,
     basic_viol, unsafe_viol, fatigued_viol, dr_fitness_viol, subt_alcohol_viol,
     vh_maint_viol, hm_viol, _source_file)
VALUES
    (995000101, 'PMK0000001', 'TX', @dot, DATE_SUB(CURDATE(), INTERVAL 26 DAY), 2,
     'TX', 1, 0, 0, 0, 0, 0, NULL,
     'TRUCK TRACTOR', 'FRHT', 'PMK1101', 'TX', '1FUJGLDR8CLBP8834',
     'SEMI-TRAILER', 'GRTD', 'TR4471', 'TX', '1GRAA0620PB123456',
     1, 1, 1, 1, 1, NULL,
     0, 0, 0, 0, 0, 0, 0, 'seed-promonkey'),
    (995000102, 'PMK0000002', 'NM', @dot, DATE_SUB(CURDATE(), INTERVAL 74 DAY), 3,
     'NM', 1, 0, 1, 0, 1, 0, NULL,
     'TRUCK TRACTOR', 'KENW', 'PMK1102', 'TX', '3AKJHHDR9LSLL1234',
     NULL, NULL, NULL, NULL, NULL,
     1, 1, 1, 1, 1, NULL,
     1, 0, 0, 0, 0, 1, 0, 'seed-promonkey'),
    (995000103, 'PMK0000003', 'AZ', @dot, DATE_SUB(CURDATE(), INTERVAL 190 DAY), 2,
     'AZ', 1, 0, 0, 0, 0, 0, NULL,
     'TRUCK TRACTOR', 'PTRB', 'PMK1103', 'OK', '1XKYDP9X4KJ256789',
     'SEMI-TRAILER', 'WABH', 'TR4472', 'TX', '1JJV532W5PL987654',
     1, 1, 1, 1, 1, NULL,
     0, 0, 0, 0, 0, 0, 0, 'seed-promonkey');

-- ---------------------------------------------------------------------------
-- 7. sms_input_crash -- the `crashes` view. One minor, non-fatal crash so the
--    panel shows real numbers rather than an empty state. Delete this block
--    if you want a spotless record.
-- ---------------------------------------------------------------------------

INSERT INTO sms_input_crash
    (report_number, report_seq_no, dot_number, report_date, report_state,
     fatalities, injuries, tow_away, hazmat_released,
     trafficway_desc, access_control_desc, road_surface_condition_desc,
     weather_condition_desc, light_condition_desc,
     vehicle_id_number, vehicle_license_number, vehicle_license_state,
     severity_weight, time_weight, citation_issued_desc, seq_num, not_preventable,
     _source_file)
VALUES
    ('PMKCRASH000001', 1, @dot, DATE_SUB(CURDATE(), INTERVAL 300 DAY), 'TX',
     0, 0, 1, NULL,
     'Two-Way Trafficway Divided Unprotected Median', 'No Access Control', 'Dry',
     'Clear', 'Daylight',
     '1FUJGLDR8CLBP8834', 'PMK1101', 'TX',
     1, 1, 'NO', 1, NULL,
     'seed-promonkey');

-- ---------------------------------------------------------------------------
-- 8. boc3_all_with_history -- the `carrier_contacts` view (process agent).
--    Only loaded when the profile is asked for with include=contacts.
-- ---------------------------------------------------------------------------

INSERT INTO boc3_all_with_history
    (docket_number, dot_number, co_name, attn_to_or_title,
     street_po, city, state_code, ctry_code, zip_code, _source_file)
VALUES
    (@docket, @dot_padded, 'PROMONKEY PROCESS AGENTS INC', 'DEEPAK SHARMA',
     '4200 DIPLOMACY RD', 'DALLAS', 'TX', 'US', '75261', 'seed-promonkey');

COMMIT;
-- ROLLBACK;

/*
| VERIFY -- read the VIEWS, not the base tables. If the views return the row,
| the application will too.
*/

-- SELECT id, row_id, dot_number, legal_name, dba_name, telephone, email_address,
--        phy_city, phy_state, mcs150_date, add_date, nbr_power_unit, driver_total
-- FROM carriers WHERE dot_number = 9500001;
--
-- SELECT dot_number, docket_number, common_stat, property_chk, bipd_file FROM carrier_authorities WHERE dot_number = 9500001;
-- SELECT dot_number, safety_rating, power_units, total_drivers, docket1prefix, docket1 FROM carrier_details WHERE dot_number = 9500001;
-- SELECT dot_number, authorized_for_hire, private_only, oic_state FROM carrier_census WHERE dot_number = 9500001;
-- SELECT dot_number, insp_total, vehicle_oos_insp_total, veh_maint_measure FROM sms_measures WHERE dot_number = 9500001;
-- SELECT dot_number, ins_type_desc, max_cov_amount, effective_date, cancl_effective_date FROM insurance_filings WHERE dot_number = 9500001;
-- SELECT dot_number, insp_date, county_code_state, unit_type_desc, vin FROM inspections WHERE dot_number = 9500001;
-- SELECT dot_number, report_date, injuries, tow_away FROM crashes WHERE dot_number = 9500001;
-- SELECT dot_number, co_name, city, state_code FROM carrier_contacts WHERE dot_number = 9500001;

/*
| CLEANUP -- every row is tagged _source_file = 'seed-promonkey', so nothing
| here can touch a real FMCSA row.
|
|   DELETE FROM boc3_all_with_history                        WHERE _source_file = 'seed-promonkey';
|   DELETE FROM sms_input_crash                              WHERE _source_file = 'seed-promonkey';
|   DELETE FROM sms_input_inspection                         WHERE _source_file = 'seed-promonkey';
|   DELETE FROM inshist_all_with_history                     WHERE _source_file = 'seed-promonkey';
|   DELETE FROM sms_ab_passproperty                          WHERE _source_file = 'seed-promonkey';
|   DELETE FROM carrier_all_with_history                     WHERE _source_file = 'seed-promonkey';
|   DELETE FROM sms_input_motor_carrier_census_information   WHERE _source_file = 'seed-promonkey';
|   DELETE FROM company_census_file                          WHERE _source_file = 'seed-promonkey';
|
| The app caches carrier lookups, so after inserting or deleting run
| `php artisan cache:clear` on the API box.
*/
