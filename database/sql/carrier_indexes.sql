-- ---------------------------------------------------------------------------
-- Indexes for the `carrier` database.
--
-- dot-extractor creates tables from the CSV header and adds no indexes beyond
-- the primary key, so every carrier lookup was a full scan (37s on
-- company_census_file). Re-run this after the loader recreates a table.
--
-- All statements are ALGORITHM=INPLACE, LOCK=NONE — MySQL 8 builds them
-- online. Total build time on the current data is about 20 minutes.
-- ---------------------------------------------------------------------------

-- Carrier identity and search. idx_legal / idx_dba carry dot_number because
-- InnoDB appends the PRIMARY KEY (here _row_id, not dot_number) to a secondary
-- index — without it the search's ORDER BY legal_name page is a full scan, and
-- a prefix index cannot satisfy ORDER BY at all.
ALTER TABLE company_census_file ADD UNIQUE INDEX uk_dot   (dot_number),                ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE company_census_file ADD INDEX        idx_legal (legal_name, dot_number),   ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE company_census_file ADD INDEX        idx_dba   (dba_name, dot_number),     ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE company_census_file ADD INDEX        idx_phone (phone),                    ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE company_census_file ADD INDEX        idx_email (email_address),            ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE company_census_file ADD INDEX        idx_duns  (dun_bradstreet_no),        ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE company_census_file ADD INDEX        idx_phy   (phy_state, phy_city, phy_street(64)), ALGORITHM=INPLACE, LOCK=NONE;
-- The company-association endpoint matches on fax and on the mailing address
-- as well. Without these two, each is a full scan of 4.48M rows inside a UNION
-- and the endpoint takes seven minutes.
ALTER TABLE company_census_file ADD INDEX        idx_fax   (fax),                        ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE company_census_file ADD INDEX        idx_mail  (carrier_mailing_street(64), carrier_mailing_city, carrier_mailing_state, carrier_mailing_zip), ALGORITHM=INPLACE, LOCK=NONE;
-- Company associations also match a carrier's FORMER physical street, which
-- arrives from the change log without the state and city idx_phy leads with.
-- Until this exists that match is another full scan, so the app leaves it off:
-- set CARRIER_FORMER_PHY_ADDRESS_MATCHING=true once this has been built.
ALTER TABLE company_census_file ADD INDEX        idx_phy_street (phy_street(64)),  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE sms_input_motor_carrier_census_information ADD INDEX idx_dot (dot_number), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE sms_ab_passproperty  ADD UNIQUE INDEX uk_dot (dot_number),                 ALGORITHM=INPLACE, LOCK=NONE;

-- Safety and roadside.
ALTER TABLE sms_input_inspection ADD INDEX idx_dot_date (dot_number, insp_date),       ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE sms_input_inspection ADD INDEX idx_uniqueid (unique_id),                   ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE sms_input_inspection ADD INDEX idx_vin      (vin),                         ALGORITHM=INPLACE, LOCK=NONE;
-- Equipment insights also match the trailing unit. Without this, joining on
-- vin2 reads every inspection row per lookup, so the app leaves it off: set
-- CARRIER_VIN2_MATCHING=true once this has been built.
ALTER TABLE sms_input_inspection ADD INDEX idx_vin2     (vin2),                        ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE sms_input_violation  ADD INDEX idx_dot      (dot_number),                  ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE sms_input_violation  ADD INDEX idx_uniqueid (unique_id),                   ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE sms_input_crash      ADD INDEX idx_dot      (dot_number),                  ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE sms_input_crash      ADD INDEX idx_report_number (report_number),          ALGORITHM=INPLACE, LOCK=NONE;
-- crashes->detail() joins on report_number; without this the profile spends 51s here.
ALTER TABLE crash_file           ADD INDEX idx_dot      (dot_number),                  ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE crash_file           ADD INDEX idx_report_number (report_number),          ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE out_of_service_orders ADD INDEX idx_dot     (dot_number),                  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE inspections_per_unit               ADD INDEX idx_insp (inspection_id),     ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE inspections_and_citations          ADD INDEX idx_insp (inspection_id),     ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE vehicle_inspections_and_violations ADD INDEX idx_insp (inspection_id),     ALGORITHM=INPLACE, LOCK=NONE;

-- The L&I family stores dot_number zero-padded to 8 characters ('00100011')
-- while the census/SMS family stores a plain int. A generated column gives the
-- views something indexable to expose as dot_number; casting per row would rule
-- the index out.
ALTER TABLE carrier_all_with_history      ADD COLUMN dot_int INT UNSIGNED GENERATED ALWAYS AS (CAST(dot_number AS UNSIGNED)) VIRTUAL;
ALTER TABLE authhist_all_with_history     ADD COLUMN dot_int INT UNSIGNED GENERATED ALWAYS AS (CAST(dot_number AS UNSIGNED)) VIRTUAL;
ALTER TABLE revocation_all_with_history   ADD COLUMN dot_int INT UNSIGNED GENERATED ALWAYS AS (CAST(dot_number AS UNSIGNED)) VIRTUAL;
ALTER TABLE boc3_all_with_history         ADD COLUMN dot_int INT UNSIGNED GENERATED ALWAYS AS (CAST(dot_number AS UNSIGNED)) VIRTUAL;
ALTER TABLE inshist_all_with_history      ADD COLUMN dot_int INT UNSIGNED GENERATED ALWAYS AS (CAST(dot_number AS UNSIGNED)) VIRTUAL;
ALTER TABLE actpendinsur_all_with_history ADD COLUMN dot_int INT UNSIGNED GENERATED ALWAYS AS (CAST(dot_number AS UNSIGNED)) VIRTUAL;
ALTER TABLE rejected_all_with_history     ADD COLUMN dot_int INT UNSIGNED GENERATED ALWAYS AS (CAST(dot_number AS UNSIGNED)) VIRTUAL;

ALTER TABLE carrier_all_with_history      ADD INDEX idx_dot_int (dot_int), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE authhist_all_with_history     ADD INDEX idx_dot_int (dot_int), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE revocation_all_with_history   ADD INDEX idx_dot_int (dot_int), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE boc3_all_with_history         ADD INDEX idx_dot_int (dot_int), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE inshist_all_with_history      ADD INDEX idx_dot_int (dot_int), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE actpendinsur_all_with_history ADD INDEX idx_dot_int (dot_int), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE rejected_all_with_history     ADD INDEX idx_dot_int (dot_int), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE authhist_all_with_history     ADD INDEX idx_dot     (dot_number), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE revocation_all_with_history   ADD INDEX idx_dot     (dot_number), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE inshist_all_with_history      ADD INDEX idx_dot     (dot_number), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE actpendinsur_all_with_history ADD INDEX idx_dot     (dot_number), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE rejected_all_with_history     ADD INDEX idx_dot     (dot_number), ALGORITHM=INPLACE, LOCK=NONE;
