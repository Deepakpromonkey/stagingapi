-- ---------------------------------------------------------------------------
-- App-facing views over the Motus/FMCSA landing tables in `carrier`.
--
-- The loader (dot-extractor) creates one table per source CSV and never
-- reshapes them, so these views are where the source vocabulary is translated
-- into the vocabulary the application speaks. Everything is a plain projection
-- so MySQL uses the MERGE algorithm and the underlying indexes still apply.
--
-- Rules that matter:
--   * Any column the app FILTERS or SORTS on must be a bare base column, never
--     an expression, or the index is unusable.
--   * dot_number is exposed as an int everywhere. The L&I tables store it
--     zero-padded ('00100011'), hence the indexed generated column dot_int.
--   * id / row_id are synthesised: the app pages on `id` and routes the
--     profile on `row_id`, and the landing tables have neither.
-- ---------------------------------------------------------------------------

CREATE OR REPLACE VIEW carriers AS
SELECT
    c.dot_number                                      AS id,
    CAST(c.dot_number AS CHAR)                        AS row_id,
    c.dot_number                                      AS dot_number,
    c.legal_name, c.dba_name, c.carrier_operation,
    c.hm_ind                                          AS hm_flag,
    c.phy_street, c.phy_city, c.phy_state, c.phy_zip, c.phy_country,
    c.carrier_mailing_street                          AS mailing_street,
    c.carrier_mailing_city                            AS mailing_city,
    c.carrier_mailing_state                           AS mailing_state,
    c.carrier_mailing_zip                             AS mailing_zip,
    c.carrier_mailing_country                         AS mailing_country,
    c.phone                                           AS telephone,
    c.fax, c.email_address,
    STR_TO_DATE(NULLIF(LEFT(c.mcs150_date, 8), ''), '%Y%m%d') AS mcs150_date,
    c.mcs150_mileage, c.mcs150_mileage_year,
    STR_TO_DATE(NULLIF(c.add_date, 0), '%Y%m%d')      AS add_date,
    c.power_units                                     AS nbr_power_unit,
    c.total_drivers                                   AS driver_total,
    c._loaded_at AS created_at, c._loaded_at AS updated_at
FROM company_census_file c;

-- The MCS-150 operation flags are deliberately NOT joined into `carriers`:
-- doing so turned the search's paging query from a 0.4s index scan into a 38s
-- full scan of 4M rows. They are a separate record instead.
CREATE OR REPLACE VIEW carrier_census AS
SELECT
    s._row_id AS id, CAST(s._row_id AS CHAR) AS row_id,
    s.dot_number, s.pc_flag, s.oic_state,
    s.recent_mileage, s.recent_mileage_year, s.vmt_source_id,
    s.private_only, s.authorized_for_hire, s.exempt_for_hire, s.private_property,
    s.private_passenger_business, s.private_passenger_nonbusiness,
    s.migrant, s.us_mail, s.federal_government, s.state_government,
    s.local_government, s.indian_tribe, s.op_other,
    s._loaded_at AS created_at, s._loaded_at AS updated_at
FROM sms_input_motor_carrier_census_information s;

CREATE OR REPLACE VIEW carrier_details AS
SELECT
    c.dot_number                                      AS id,
    CAST(c.dot_number AS CHAR)                        AS row_id,
    c.dot_number,
    STR_TO_DATE(NULLIF(c.add_date, 0), '%Y%m%d')      AS add_date,
    c.status_code, c.dun_bradstreet_no, c.phy_omc_region, c.safety_inv_terr,
    c.business_org_id, c.business_org_desc, c.mcs151_mileage, c.mcs150_update_code_id,
    c.prior_revoke_flag, c.prior_revoke_dot_number,
    c.phone, c.fax, c.cell_phone, c.company_officer_1, c.company_officer_2,
    c.total_cars, c.truck_units, c.power_units, c.bus_units, c.fleetsize,
    c.review_id, c.review_type,
    STR_TO_DATE(NULLIF(c.review_date, 0), '%Y%m%d')          AS review_date,
    c.safety_rating,
    STR_TO_DATE(NULLIF(c.safety_rating_date, 0), '%Y%m%d')   AS safety_rating_date,
    c.recordable_crash_rate, c.mail_nationality_indicator, c.phy_nationality_indicator,
    c.phy_barrio, c.mail_barrio, c.carship,
    c.docket1prefix, c.docket1, c.docket1_status_code,
    c.docket2prefix, c.docket2, c.docket2_status_code,
    c.docket3prefix, c.docket3, c.docket3_status_code,
    c.pointnum, c.mcsipstep,
    STR_TO_DATE(NULLIF(c.mcsipdate, 0), '%Y%m%d')     AS mcsipdate,
    c.total_intrastate_drivers, c.total_cdl, c.total_drivers,
    c.avg_drivers_leased_per_month, c.driver_inter_total, c.hm_ind,
    c.interstate_beyond_100_miles, c.interstate_within_100_miles,
    c.intrastate_beyond_100_miles, c.intrastate_within_100_miles,
    c.classdef,
    c.carrier_mailing_street, c.carrier_mailing_state, c.carrier_mailing_city,
    c.carrier_mailing_country, c.carrier_mailing_zip, c.carrier_mailing_cnty,
    STR_TO_DATE(NULLIF(c.carrier_mailing_und_date, 0), '%Y%m%d') AS carrier_mailing_und_date,
    c.phy_cnty, c.undeliv_phy,
    c.crgo_genfreight, c.crgo_household, c.crgo_metalsheet, c.crgo_motoveh,
    c.crgo_drivetow, c.crgo_logpole, c.crgo_bldgmat, c.crgo_mobilehome,
    c.crgo_machlrg, c.crgo_produce, c.crgo_liqgas, c.crgo_intermodal,
    c.crgo_passengers, c.crgo_oilfield, c.crgo_livestock, c.crgo_grainfeed,
    c.crgo_coalcoke, c.crgo_meat, c.crgo_garbage, c.crgo_usmail, c.crgo_chem,
    c.crgo_drybulk, c.crgo_coldfood, c.crgo_beverages, c.crgo_paperprod,
    c.crgo_utility, c.crgo_farmsupp, c.crgo_construct, c.crgo_waterwell,
    c.crgo_cargoothr, c.crgo_cargoothr_desc,
    c.owntruck, c.owntract, c.owntrail, c.owncoach,
    c.trmtruck, c.trmtract, c.trmtrail, c.trmcoach,
    c.trptruck, c.trptract, c.trptrail, c.trpcoach,
    c._loaded_at AS created_at, c._loaded_at AS updated_at
FROM company_census_file c;

CREATE OR REPLACE VIEW carrier_authorities AS
SELECT a._row_id AS id, CAST(a._row_id AS CHAR) AS row_id,
       a.docket_number, a.dot_int AS dot_number, a.mx_type, a.rfc_number,
       a.common_stat, a.contract_stat, a.broker_stat,
       a.common_app_pend, a.contract_app_pend, a.broker_app_pend,
       a.common_rev_pend, a.contract_rev_pend, a.broker_rev_pend,
       a.property_chk, a.passenger_chk, a.hhg_chk, a.private_auth_chk, a.enterprise_chk,
       a.min_cov_amount, a.cargo_req, a.bond_req, a.bipd_file, a.cargo_file, a.bond_file,
       a.undeliverable_mail, a.dba_name, a.legal_name,
       a.bus_street_po, a.bus_colonia, a.bus_city, a.bus_state_code, a.bus_ctry_code,
       a.bus_zip_code, a.bus_telno, a.bus_fax,
       a.mail_street_po, a.mail_colonia, a.mail_city, a.mail_state_code, a.mail_ctry_code,
       a.mail_zip_code, a.mail_telno, a.mail_fax,
       a._loaded_at AS created_at, a._loaded_at AS updated_at
FROM carrier_all_with_history a;

CREATE OR REPLACE VIEW carrier_authority_history AS
SELECT h._row_id AS id, CAST(h._row_id AS CHAR) AS row_id,
       h.docket_number, h.dot_int AS dot_number, h.sub_number, h.op_auth_type,
       h.original_action_desc, h.orig_served_date, h.disp_action_desc,
       h.disp_decided_date, h.disp_served_date,
       h._loaded_at AS created_at, h._loaded_at AS updated_at
FROM authhist_all_with_history h;

CREATE OR REPLACE VIEW carrier_authority_orders AS
SELECT r._row_id AS id, CAST(r._row_id AS CHAR) AS row_id,
       r.docket_number, r.dot_int AS dot_number, r.type_license,
       r.order1_serve_date, r.order2_type_desc, r.order2_effective_date,
       r._loaded_at AS created_at, r._loaded_at AS updated_at
FROM revocation_all_with_history r;

CREATE OR REPLACE VIEW carrier_contacts AS
SELECT b._row_id AS id, CAST(b._row_id AS CHAR) AS row_id,
       b.docket_number, b.dot_int AS dot_number, b.co_name, b.attn_to_or_title,
       b.street_po, b.city, b.state_code, b.ctry_code, b.zip_code,
       b._loaded_at AS created_at, b._loaded_at AS updated_at
FROM boc3_all_with_history b;

CREATE OR REPLACE VIEW carrier_oos_orders AS
SELECT o._row_id AS id, CAST(o._row_id AS CHAR) AS row_id,
       o.dot_number, o.legal_name, o.dba_name, o.oos_date, o.oos_reason,
       o.status, o.rescind_date,
       o._loaded_at AS created_at, o._loaded_at AS updated_at
FROM out_of_service_orders o;

CREATE OR REPLACE VIEW sms_measures AS
SELECT m._row_id AS id, CAST(m._row_id AS CHAR) AS row_id,
       m.dot_number, m.insp_total, m.driver_insp_total, m.driver_oos_insp_total,
       m.vehicle_insp_total, m.vehicle_oos_insp_total,
       m.unsafe_driv_insp_w_viol, m.unsafe_driv_measure, m.unsafe_driv_ac,
       m.hos_driv_insp_w_viol, m.hos_driv_measure, m.hos_driv_ac,
       m.driv_fit_insp_w_viol, m.driv_fit_measure, m.driv_fit_ac,
       m.contr_subst_insp_w_viol, m.contr_subst_measure, m.contr_subst_ac,
       m.veh_maint_insp_w_viol, m.veh_maint_measure, m.veh_maint_ac,
       m._loaded_at AS created_at, m._loaded_at AS updated_at
FROM sms_ab_passproperty m;

CREATE OR REPLACE VIEW inspections AS
SELECT i._row_id AS id, CAST(i._row_id AS CHAR) AS row_id,
       i.unique_id, i.report_number, i.report_state, i.dot_number, i.insp_date,
       i.insp_level_id, i.county_code_state, i.time_weight,
       i.driver_oos_total, i.vehicle_oos_total, i.total_hazmat_sent,
       i.oos_total, i.hazmat_oos_total, i.hazmat_placard_req,
       i.unit_type_desc, i.unit_make, i.unit_license, i.unit_license_state,
       i.vin, i.unit_decal_number,
       i.unit_type_desc2, i.unit_make2, i.unit_license2, i.unit_license_state2,
       i.vin2, i.unit_decal_number2,
       i.unsafe_insp, i.fatigued_insp, i.dr_fitness_insp, i.subt_alcohol_insp,
       i.vh_maint_insp, i.hm_insp,
       i.basic_viol, i.unsafe_viol, i.fatigued_viol, i.dr_fitness_viol,
       i.subt_alcohol_viol, i.vh_maint_viol, i.hm_viol,
       i._loaded_at AS created_at, i._loaded_at AS updated_at
FROM sms_input_inspection i;

CREATE OR REPLACE VIEW violation_details AS
SELECT v._row_id AS id, CAST(v._row_id AS CHAR) AS row_id,
       v.unique_id, v.insp_date, v.dot_number, v.viol_code, v.basic_desc,
       v.oos_indicator, v.oos_weight, v.severity_weight, v.time_weight,
       v.total_severity_wght, v.section_desc, v.group_desc, v.viol_unit,
       v._loaded_at AS created_at, v._loaded_at AS updated_at
FROM sms_input_violation v;

CREATE OR REPLACE VIEW inspection_units AS
SELECT u._row_id AS id, CAST(u._row_id AS CHAR) AS row_id,
       u.change_date, u.inspection_id, u.insp_unit_id, u.insp_unit_type_id,
       u.insp_unit_number, u.insp_unit_make, u.insp_unit_company,
       u.insp_unit_license, u.insp_unit_license_state,
       u.insp_unit_vehicle_id_number, u.insp_unit_decal, u.insp_unit_decal_number,
       u._loaded_at AS created_at, u._loaded_at AS updated_at
FROM inspections_per_unit u;

CREATE OR REPLACE VIEW inspection_citations AS
SELECT c._row_id AS id, CAST(c._row_id AS CHAR) AS row_id,
       c.change_date, c.inspection_id, c.vioseqnum, c.adjseq,
       c.citation_code, c.citation_result,
       c._loaded_at AS created_at, c._loaded_at AS updated_at
FROM inspections_and_citations c;

CREATE OR REPLACE VIEW inspection_violations AS
SELECT w._row_id AS id, CAST(w._row_id AS CHAR) AS row_id,
       w.change_date, w.inspection_id, w.inspection_id AS inspection_unique_id,
       w.insp_violation_id, w.seq_no, w.part_no, w.part_no_section,
       w.insp_viol_unit, w.insp_unit_id, w.insp_violation_category_id,
       w.out_of_service_indicator, w.defect_verification_id, w.citation_number,
       w.viol_code, w.viol_desc,
       w._loaded_at AS created_at, w._loaded_at AS updated_at
FROM vehicle_inspections_and_violations w;

CREATE OR REPLACE VIEW crashes AS
SELECT k._row_id AS id, CAST(k._row_id AS CHAR) AS row_id,
       k.report_number, k.report_seq_no, k.dot_number, k.report_date, k.report_state,
       k.fatalities, k.injuries, k.tow_away, k.hazmat_released,
       k.trafficway_desc, k.access_control_desc, k.road_surface_condition_desc,
       k.weather_condition_desc, k.light_condition_desc,
       k.vehicle_id_number, k.vehicle_license_number, k.vehicle_license_state,
       k.severity_weight, k.time_weight, k.citation_issued_desc,
       k.seq_num, k.not_preventable,
       k._loaded_at AS created_at, k._loaded_at AS updated_at
FROM sms_input_crash k;

CREATE OR REPLACE VIEW crash_details AS
SELECT d._row_id AS id, CAST(d._row_id AS CHAR) AS row_id,
       d.change_date, d.crash_id, d.report_state, d.report_number,
       STR_TO_DATE(NULLIF(d.report_date, 0), '%Y%m%d')       AS report_date,
       d.report_time, d.report_seq_no, d.dot_number, d.ci_status_code,
       STR_TO_DATE(NULLIF(d.final_status_date, 0), '%Y%m%d') AS final_status_date,
       d.location, d.city_code, d.city, d.state, d.county_code, d.truck_bus_ind,
       d.trafficway_id, d.access_control_id, d.road_surface_condition_id,
       d.cargo_body_type_id, d.gvw_rating_id,
       d.vehicle_identification_number, d.vehicle_license_number, d.vehicle_lic_state,
       d.vehicle_hazmat_placard, d.weather_condition_id, d.vehicle_configuration_id,
       d.light_condition_id, d.hazmat_released, d.agency, d.vehicles_in_accident,
       d.fatalities, d.injuries, d.tow_away, d.federal_recordable, d.state_recordable,
       d.snet_version_number, d.snet_sequence_id,
       d._loaded_at AS created_at, d._loaded_at AS updated_at
FROM crash_file d;

CREATE OR REPLACE VIEW insurance_filings AS
SELECT f._row_id AS id, CAST(f._row_id AS CHAR) AS row_id,
       f.docket_number, f.dot_int AS dot_number, f.ins_form_code,
       f.cancl_method_gen, f.ins_cancl_form, f.ins_type_ind, f.ins_type_desc,
       f.policy_no, f.min_cov_amount, f.ins_class_code, f.effective_date,
       f.underl_lim_amount, f.max_cov_amount, f.cancl_effective_date,
       f.cancl_method, f.inser_branch, f.name_company,
       f._loaded_at AS created_at, f._loaded_at AS updated_at
FROM inshist_all_with_history f;

CREATE OR REPLACE VIEW insurance_filings_history AS
SELECT h._row_id AS id, CAST(h._row_id AS CHAR) AS row_id,
       h.docket_number, h.dot_int AS dot_number, h.ins_form_code,
       h.mod_col_1 AS ins_type_desc, h.name_company, h.policy_no, h.trans_date,
       h.underl_lim_amount, h.max_cov_amount, h.effective_date, h.cancl_effective_date,
       h._loaded_at AS created_at, h._loaded_at AS updated_at
FROM actpendinsur_all_with_history h;

CREATE OR REPLACE VIEW insurance_filings_pending AS
SELECT j._row_id AS id, CAST(j._row_id AS CHAR) AS row_id,
       j.docket_number, j.dot_int AS dot_number, j.ins_form_code,
       j.mod_col_1 AS ins_type_desc, j.policy_no, j.recv_date, j.ins_class_code,
       j.mod_col_2 AS ins_type_ind, j.mod_col_3 AS underl_lim_amount,
       j.mod_col_4 AS max_cov_amount, j.rej_date, j.inser_branch, j.name_company,
       j.rej_reasons, j.min_cov_amount,
       j._loaded_at AS created_at, j._loaded_at AS updated_at
FROM rejected_all_with_history j;

CREATE OR REPLACE VIEW broker_insurance AS
SELECT b._row_id AS id, CAST(b._row_id AS CHAR) AS row_id,
       b.prefix_docket_number, b.ins_type_code, b.ins_class_code,
       b.max_cov_amount, b.underl_lim_amount, b.policy_no, b.effective_date,
       b.ins_form_code, b.name_company,
       b._loaded_at AS created_at, b._loaded_at AS updated_at
FROM insur_all_with_history b;
