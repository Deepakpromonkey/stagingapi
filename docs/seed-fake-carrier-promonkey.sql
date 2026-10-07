/*
| Seeds one complete fake carrier — "Promonkey Logistics LLC" — across every
| local table a real onboarded carrier touches.
|
| Run against the APPLICATION database (the one holding carrier_users,
| carrier_connect_requests, eld_*). Nothing here writes to `external_db`:
| the FMCSA `carriers` directory there is a read-only VIEW over
| company_census_file and cannot be inserted into — see the note at the end.
|
| Login it creates:  deepak@promonkey.tech / Promonkey@123
|                    (2FA off, no forced password change)
|
| Everything runs in one transaction. Check the SELECTs at the bottom, then
| COMMIT (or ROLLBACK to throw it away).
*/

-- ---------------------------------------------------------------------------
-- 0. Knobs. Set these three before running.
-- ---------------------------------------------------------------------------

-- Pick a DOT number that EXISTS in the FMCSA census, otherwise the broker-side
-- carrier profile / search screens will not resolve this carrier. Find one:
--     SELECT dot_number, legal_name FROM carriers WHERE dot_number = 3948123;   -- on external_db
SET @dot_number := '3948123';

-- The broker company + user this carrier was onboarded by.
SET @company_id := (SELECT id FROM companies ORDER BY id LIMIT 1);
SET @user_id    := (SELECT id FROM users WHERE company_id = @company_id ORDER BY id LIMIT 1);

SET @legal_name := 'Promonkey Logistics LLC';
SET @email      := 'deepak@promonkey.tech';
SET @phone      := '+918076734039';

-- bcrypt(cost 12) of "Promonkey@123"
SET @password   := '$2y$12$ldGBzR.zAOsbnEwtvcSpeub6iaBspVBBlqAplA.AMXKE9YUJ.H5f6';

SET @now := NOW();

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. carrier_companies — the trucking company behind the portal logins
-- ---------------------------------------------------------------------------

INSERT INTO carrier_companies
    (uuid, legal_name, dot_number, phone, status, created_at, updated_at)
VALUES
    (UUID(), @legal_name, @dot_number, @phone, 1, @now, @now);

SET @carrier_company_id := LAST_INSERT_ID();

-- ---------------------------------------------------------------------------
-- 2. carrier_users — the owner (your login) plus one invited dispatcher
-- ---------------------------------------------------------------------------

INSERT INTO carrier_users
    (uuid, carrier_company_id, first_name, last_name, email, password,
     must_change_password, legal_name, dot_number, phone, profile_image,
     status, two_factor_enabled, is_owner, invited_by,
     email_verified_at, last_login_at, last_login_ip, last_password_changed_at,
     created_at, updated_at)
VALUES
    (UUID(), @carrier_company_id, 'Deepak', 'Sharma', @email, @password,
     0, @legal_name, @dot_number, @phone, NULL,
     1, 0, 1, NULL,
     @now, @now, '103.21.58.14', @now,
     @now, @now);

SET @owner_id := LAST_INSERT_ID();

INSERT INTO carrier_users
    (uuid, carrier_company_id, first_name, last_name, email, password,
     must_change_password, legal_name, dot_number, phone,
     status, two_factor_enabled, is_owner, invited_by,
     email_verified_at, created_at, updated_at)
VALUES
    (UUID(), @carrier_company_id, 'Marcus', 'Reed', 'dispatch@promonkey.tech', @password,
     0, @legal_name, @dot_number, '+15550142201',
     1, 0, 0, @owner_id,
     @now, @now, @now);

SET @staff_id := LAST_INSERT_ID();

-- Seats, under the `carrier` Spatie guard (config/carrier_rbac.php)
INSERT IGNORE INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\\Models\\CarrierUser', @owner_id
FROM roles r WHERE r.name = 'carrier_owner' AND r.guard_name = 'carrier';

INSERT IGNORE INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\\Models\\CarrierUser', @staff_id
FROM roles r WHERE r.name = 'carrier_staff' AND r.guard_name = 'carrier';

-- ---------------------------------------------------------------------------
-- 3. carrier_connect_requests — a finished onboarding
-- ---------------------------------------------------------------------------

INSERT INTO carrier_connect_requests
    (uuid, company_id, user_id, prefilled_from_request_id, prefilled_at,
     carrier_user_id, carrier_row_id, carrier_dot_number, carrier_legal_name,
     carrier_email, pending_email, pending_email_token,
     pending_email_requested_at, pending_email_approved_at, carrier_phone,
     token, status, sent_on, first_visit_at, onboarding_ip,
     email_verified_at, mobile_verified_at,
     otp, otp_sent_at, otp_attempts, last_otp_attempt_at,
     didit_session_id, didit_status, didit_responded_at, didit_response,
     didit_risk_flagged, didit_registration_ip, identity_skipped_at,
     stripe_express_account, stripe_verified_at, bank_skipped_at,
     eld_connection_id, eld_link_state, eld_connected_at, eld_skipped_at,
     uses_factoring_company, factoring_company_name,
     factoring_document_disk, factoring_document_path, factoring_document_name,
     factoring_answered_at, questionnaire_completed_at, documents_completed_at,
     agreement_document_id, signature_disk, signature_path, signature_page,
     signature_x_pct, signature_y_pct, signed_at,
     portal_account_provisioned_at, portal_account_email, portal_account_error,
     created_at, updated_at)
VALUES
    (UUID(), @company_id, @user_id, NULL, NULL,
     @owner_id, @dot_number, @dot_number, @legal_name,
     @email, NULL, NULL,
     NULL, NULL, @phone,
     REPLACE(CONCAT(UUID(), UUID()), '-', ''), 'completed',
     DATE_SUB(@now, INTERVAL 9 DAY), DATE_SUB(@now, INTERVAL 9 DAY), '103.21.58.14',
     DATE_SUB(@now, INTERVAL 9 DAY), DATE_SUB(@now, INTERVAL 9 DAY),
     NULL, DATE_SUB(@now, INTERVAL 9 DAY), 0, DATE_SUB(@now, INTERVAL 9 DAY),
     'dds_fake_9f21c4a7', 'Approved', DATE_SUB(@now, INTERVAL 8 DAY),
     JSON_OBJECT('vendor_data', @dot_number,
                 'status', 'Approved',
                 'decision', JSON_OBJECT('kyc', JSON_OBJECT('document_type', 'Passport',
                                                            'first_name', 'Deepak',
                                                            'last_name', 'Sharma'))),
     0, '103.21.58.14', NULL,
     'acct_1QfakePromonkey01', DATE_SUB(@now, INTERVAL 8 DAY), NULL,
     NULL, NULL, NULL, NULL,
     1, 'RTS Financial Factoring',
     'public', 'carrier-connect/promonkey/noa-letter.pdf', 'noa-letter.pdf',
     DATE_SUB(@now, INTERVAL 8 DAY), DATE_SUB(@now, INTERVAL 7 DAY), DATE_SUB(@now, INTERVAL 7 DAY),
     (SELECT id FROM broker_agreement_documents WHERE company_id = @company_id ORDER BY id DESC LIMIT 1),
     'public', 'carrier-connect/promonkey/signature.png', 1,
     62.500, 78.250, DATE_SUB(@now, INTERVAL 7 DAY),
     DATE_SUB(@now, INTERVAL 7 DAY), @email, NULL,
     DATE_SUB(@now, INTERVAL 9 DAY), @now);

SET @request_id := LAST_INSERT_ID();

-- ---------------------------------------------------------------------------
-- 4. carrier_connect_documents — W-9 + COI
-- ---------------------------------------------------------------------------

INSERT INTO carrier_connect_documents
    (carrier_connect_request_id, type, disk, path, name, size, mime, created_at, updated_at)
VALUES
    (@request_id, 'w9',  'public', 'carrier-connect/promonkey/w9.pdf',  'promonkey-w9.pdf',  184320, 'application/pdf', @now, @now),
    (@request_id, 'coi', 'public', 'carrier-connect/promonkey/coi.pdf', 'promonkey-coi.pdf', 268914, 'application/pdf', @now, @now);

-- ---------------------------------------------------------------------------
-- 5. carrier_connect_answers — answers to whatever this broker asks
-- ---------------------------------------------------------------------------

INSERT INTO carrier_connect_answers
    (carrier_connect_request_id, carrier_question_id, question_text, answer_type,
     answer, answer_document_disk, answer_document_path, answer_document_name,
     created_at, updated_at)
SELECT
    @request_id,
    q.id,
    q.question,
    q.answer_type,
    CASE
        WHEN LOWER(q.answer_type) LIKE '%yes%'   THEN 'Yes'
        WHEN LOWER(q.answer_type) LIKE '%number%' THEN '12'
        WHEN LOWER(q.answer_type) LIKE '%image%'  THEN NULL
        WHEN LOWER(q.answer_type) LIKE '%upload%' THEN NULL
        ELSE 'Yes — 12 power units, all dry van, MC authority active since 2019.'
    END,
    CASE WHEN LOWER(q.answer_type) LIKE '%upload%' OR LOWER(q.answer_type) LIKE '%image%' THEN 'public' END,
    CASE WHEN LOWER(q.answer_type) LIKE '%upload%' OR LOWER(q.answer_type) LIKE '%image%' THEN 'carrier-connect/promonkey/answer-doc.pdf' END,
    CASE WHEN LOWER(q.answer_type) LIKE '%upload%' OR LOWER(q.answer_type) LIKE '%image%' THEN 'answer-doc.pdf' END,
    @now, @now
FROM carrier_questions q
WHERE q.company_id = @company_id;

-- ---------------------------------------------------------------------------
-- 6. eld_connections — a linked Terminal account
-- ---------------------------------------------------------------------------

INSERT INTO eld_connections
    (uuid, terminal_connection_id, connection_token, provider_code, provider_name,
     status, external_id, account_name, dot_numbers,
     sync_status, sync_progress, last_synced_at, last_sync_error,
     connected_at, disconnected_at, payload, created_at, updated_at)
VALUES
    (UUID(), CONCAT('conn_fake_', @dot_number), CONCAT('con_tkn_fake_', @dot_number),
     'samsara', 'Samsara',
     'connected', CAST(@request_id AS CHAR), 'Promonkey Logistics LLC', JSON_ARRAY(@dot_number),
     'success', 100, DATE_SUB(@now, INTERVAL 20 MINUTE), NULL,
     DATE_SUB(@now, INTERVAL 7 DAY), NULL,
     JSON_OBJECT('id', CONCAT('conn_fake_', @dot_number),
                 'status', 'connected',
                 'provider', 'samsara',
                 'accountName', 'Promonkey Logistics LLC'),
     DATE_SUB(@now, INTERVAL 7 DAY), @now);

SET @eld_id := LAST_INSERT_ID();

UPDATE carrier_connect_requests
SET eld_connection_id = @eld_id,
    eld_connected_at  = DATE_SUB(@now, INTERVAL 7 DAY)
WHERE id = @request_id;

-- 6a. Vehicles

INSERT INTO eld_vehicles
    (eld_connection_id, terminal_id, source_id, provider, status, vin, name,
     make, model, year, license_plate_state, license_plate_number, payload, created_at, updated_at)
VALUES
    (@eld_id, 'vcl_fake_001', 'sam-1001', 'samsara', 'active', '1FUJGLDR8CLBP8834', 'Truck 101',
     'Freightliner', 'Cascadia', 2021, 'TX', 'PMK1101',
     JSON_OBJECT('id','vcl_fake_001','vin','1FUJGLDR8CLBP8834','make','Freightliner'), @now, @now),
    (@eld_id, 'vcl_fake_002', 'sam-1002', 'samsara', 'active', '3AKJHHDR9LSLL1234', 'Truck 102',
     'Kenworth', 'T680', 2020, 'TX', 'PMK1102',
     JSON_OBJECT('id','vcl_fake_002','vin','3AKJHHDR9LSLL1234','make','Kenworth'), @now, @now),
    (@eld_id, 'vcl_fake_003', 'sam-1003', 'samsara', 'inactive', '1XKYDP9X4KJ256789', 'Truck 103',
     'Peterbilt', '579', 2019, 'OK', 'PMK1103',
     JSON_OBJECT('id','vcl_fake_003','vin','1XKYDP9X4KJ256789','make','Peterbilt'), @now, @now);

-- 6b. Drivers

INSERT INTO eld_drivers
    (eld_connection_id, terminal_id, source_id, provider, status,
     first_name, last_name, email, phone, license_number, license_state,
     payload, created_at, updated_at)
VALUES
    (@eld_id, 'drv_fake_001', 'sam-d1', 'samsara', 'active',
     'Carlos', 'Ibarra', 'carlos.ibarra@promonkey.tech', '+15550142310', 'TX48891023', 'TX',
     JSON_OBJECT('id','drv_fake_001','firstName','Carlos','lastName','Ibarra'), @now, @now),
    (@eld_id, 'drv_fake_002', 'sam-d2', 'samsara', 'active',
     'Nina', 'Whitaker', 'nina.whitaker@promonkey.tech', '+15550142311', 'TX48891987', 'TX',
     JSON_OBJECT('id','drv_fake_002','firstName','Nina','lastName','Whitaker'), @now, @now);

-- 6c. Last known position, one pin per truck

INSERT INTO eld_vehicle_locations
    (eld_connection_id, vehicle_terminal_id, latitude, longitude, speed, heading,
     description, located_at, payload, created_at, updated_at)
VALUES
    (@eld_id, 'vcl_fake_001', 32.7766640, -96.7969790, 62.50, 271.00,
     'I-20 W near Dallas, TX', DATE_SUB(@now, INTERVAL 12 MINUTE),
     JSON_OBJECT('lat',32.776664,'lon',-96.796979), @now, @now),
    (@eld_id, 'vcl_fake_002', 35.2219970, -101.8312990, 0.00, 0.00,
     'Truck stop, Amarillo, TX', DATE_SUB(@now, INTERVAL 40 MINUTE),
     JSON_OBJECT('lat',35.221997,'lon',-101.831299), @now, @now),
    (@eld_id, 'vcl_fake_003', 35.4675600, -97.5164300, 0.00, 0.00,
     'Yard, Oklahoma City, OK', DATE_SUB(@now, INTERVAL 3 HOUR),
     JSON_OBJECT('lat',35.46756,'lon',-97.51643), @now, @now);

-- 6d. Hours of service, the last day for each driver

INSERT INTO eld_hos_logs
    (eld_connection_id, terminal_id, source_id, provider, status,
     driver_terminal_id, vehicle_terminal_id, started_at, ended_at,
     latitude, longitude, remarks, payload, created_at, updated_at)
VALUES
    (@eld_id, 'hos_fake_001', 'sam-h1', 'samsara', 'off_duty',
     'drv_fake_001', 'vcl_fake_001', DATE_SUB(@now, INTERVAL 22 HOUR), DATE_SUB(@now, INTERVAL 12 HOUR),
     32.7766640, -96.7969790, 'Reset at terminal', JSON_OBJECT('status','off_duty'), @now, @now),
    (@eld_id, 'hos_fake_002', 'sam-h2', 'samsara', 'on_duty',
     'drv_fake_001', 'vcl_fake_001', DATE_SUB(@now, INTERVAL 12 HOUR), DATE_SUB(@now, INTERVAL 11 HOUR),
     32.7766640, -96.7969790, 'Pre-trip inspection', JSON_OBJECT('status','on_duty'), @now, @now),
    (@eld_id, 'hos_fake_003', 'sam-h3', 'samsara', 'driving',
     'drv_fake_001', 'vcl_fake_001', DATE_SUB(@now, INTERVAL 11 HOUR), NULL,
     32.7766640, -96.7969790, NULL, JSON_OBJECT('status','driving'), @now, @now),
    (@eld_id, 'hos_fake_004', 'sam-h4', 'samsara', 'sleeper',
     'drv_fake_002', 'vcl_fake_002', DATE_SUB(@now, INTERVAL 9 HOUR), NULL,
     35.2219970, -101.8312990, '10 hour break', JSON_OBJECT('status','sleeper'), @now, @now);

-- ---------------------------------------------------------------------------
-- 7. carrier_fleet_stats — keyed by DOT, so upsert
-- ---------------------------------------------------------------------------

INSERT INTO carrier_fleet_stats
    (dot_number, avg_power_age, avg_trailer_age, power_units, trailers,
     vins_total, vins_decoded, computed_at, created_at, updated_at)
VALUES
    (@dot_number, 4.3, 6.8, 12, 18, 30, 27, @now, @now, @now)
ON DUPLICATE KEY UPDATE
    avg_power_age = VALUES(avg_power_age),
    avg_trailer_age = VALUES(avg_trailer_age),
    power_units = VALUES(power_units),
    trailers = VALUES(trailers),
    vins_total = VALUES(vins_total),
    vins_decoded = VALUES(vins_decoded),
    computed_at = VALUES(computed_at),
    updated_at = VALUES(updated_at);

-- ---------------------------------------------------------------------------
-- 8. Broker-side references (carrier_id here IS the DOT number)
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO carrier_shortlists (company_id, user_id, carrier_id, created_at, updated_at)
VALUES (@company_id, @user_id, @dot_number, @now, @now);

INSERT INTO search_histories (company_id, user_id, carrier_id, dt_score, created_at, updated_at)
VALUES (@company_id, @user_id, @dot_number, 82, @now, @now);

-- ---------------------------------------------------------------------------
-- 9. drivers — the phone-keyed driver app accounts
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO drivers
    (uuid, phone_e164, name, phone_verified_at, last_login_at, last_login_ip, is_active, created_at, updated_at)
VALUES
    (UUID(), '+15550142310', 'Carlos Ibarra',  DATE_SUB(@now, INTERVAL 6 DAY), DATE_SUB(@now, INTERVAL 1 DAY), '103.21.58.14', 1, @now, @now),
    (UUID(), '+15550142311', 'Nina Whitaker',  DATE_SUB(@now, INTERVAL 5 DAY), DATE_SUB(@now, INTERVAL 2 DAY), '103.21.58.14', 1, @now, @now);

-- ---------------------------------------------------------------------------
-- 10. shipments — one active load tendered to this carrier
-- ---------------------------------------------------------------------------

INSERT INTO shipments
    (uuid, company_id, created_by, updated_by, shipment_no, pro_number,
     carrier_name, carrier_mc, carrier_dot, carrier_phone, carrier_extension,
     tracking_method, country_code, tracking_number,
     truck_number, trailer_number, driver_phone_1, driver_phone_2, driver_phone_3,
     driver_type, team_load, tracking_start_at, notes, status, created_at, updated_at)
VALUES
    (UUID(), @company_id, @user_id, @user_id,
     CONCAT('PMK-', DATE_FORMAT(@now, '%y%m%d'), '-001'), 'PRO889231',
     @legal_name, 'MC1099231', @dot_number, @phone, NULL,
     'eld', '+1', '+15550142310',
     '101', 'TR-4471', '+15550142310', NULL, NULL,
     'company_driver', 0, DATE_SUB(@now, INTERVAL 11 HOUR),
     'Fake seed load for Promonkey Logistics. Safe to delete.', 'active',
     DATE_SUB(@now, INTERVAL 1 DAY), @now);

SET @shipment_id := LAST_INSERT_ID();

INSERT INTO shipment_stops
    (shipment_id, stop_number, stop_type, stop_name, contact_name, contact_phone,
     address, address_2, city, state, zipcode, country, latitude, longitude,
     start_date, start_time, start_timezone, end_date, end_time, end_timezone,
     comment_to_driver, alert_emails, events, requires_otp, otp_code, otp_verified_at,
     created_at, updated_at)
VALUES
    (@shipment_id, 1, 'Pickup', 'Dallas DC', 'Ray Alvarez', '+15550142380',
     '4200 Diplomacy Rd', NULL, 'Dallas', 'TX', '75261', 'USA', 32.8340000, -96.9440000,
     DATE(DATE_SUB(@now, INTERVAL 1 DAY)), '08:00', 'America/Chicago',
     DATE(DATE_SUB(@now, INTERVAL 1 DAY)), '12:00', 'America/Chicago',
     'Dock 14, check in with the guard.', @email, JSON_ARRAY('arrived', 'loaded'),
     1, '4821', DATE_SUB(@now, INTERVAL 20 HOUR), @now, @now),
    (@shipment_id, 2, 'Delivery', 'Phoenix Cross-dock', 'Tina Boyle', '+15550142381',
     '2075 W Buckeye Rd', 'Suite B', 'Phoenix', 'AZ', '85009', 'USA', 33.4360000, -112.1030000,
     DATE(DATE_ADD(@now, INTERVAL 1 DAY)), '09:00', 'America/Phoenix',
     DATE(DATE_ADD(@now, INTERVAL 1 DAY)), '15:00', 'America/Phoenix',
     'Appointment required. Lumper paid by broker.', @email, JSON_ARRAY(),
     1, '7736', NULL, @now, @now);

INSERT INTO shipment_tracking_updates
    (shipment_id, sequence, date_time, tracking_days, `interval`, created_at, updated_at)
VALUES
    (@shipment_id, 1, DATE_SUB(@now, INTERVAL 1 DAY), 'Mon,Tue,Wed,Thu,Fri', '4', @now, @now);

-- ---------------------------------------------------------------------------
-- 11. coi_insurance_requests — one chased, one answered
-- ---------------------------------------------------------------------------

INSERT INTO coi_insurance_requests
    (uuid, company_id, user_id, dot_number, carrier_name, carrier_mc,
     recipient_email, recipient_source, status, reply_token, subject, message_id,
     insurance_expiry_date, sent_at, responded_at, resolved_at, last_error,
     created_at, updated_at)
VALUES
    (UUID(), @company_id, @user_id, @dot_number, @legal_name, 'MC1099231',
     'agent@fakeinsurance.test', 'ocr', 'success',
     REPLACE(UUID(), '-', ''), CONCAT('COI request — ', @legal_name),
     CONCAT('<fake-', @dot_number, '@dollartraq.test>'),
     DATE_ADD(@now, INTERVAL 8 MONTH), DATE_SUB(@now, INTERVAL 4 DAY),
     DATE_SUB(@now, INTERVAL 3 DAY), DATE_SUB(@now, INTERVAL 3 DAY), NULL,
     DATE_SUB(@now, INTERVAL 4 DAY), @now);

SET @coi_id := LAST_INSERT_ID();

INSERT INTO coi_insurance_responses
    (uuid, coi_insurance_request_id, from_email, from_name, subject,
     body_text, body_html, raw_payload, llm_response, extracted_expiry_date,
     received_at, created_at, updated_at)
VALUES
    (UUID(), @coi_id, 'agent@fakeinsurance.test', 'Fake Insurance Agency',
     CONCAT('RE: COI request — ', @legal_name),
     'Attached is the current certificate. Policy runs through the date below and auto-renews.',
     '<p>Attached is the current certificate.</p>',
     JSON_OBJECT('provider', 'seed', 'from', 'agent@fakeinsurance.test'),
     CONCAT('{"expiry_date":"', DATE_FORMAT(DATE_ADD(@now, INTERVAL 8 MONTH), '%Y-%m-%d'), '"}'),
     DATE_ADD(@now, INTERVAL 8 MONTH), DATE_SUB(@now, INTERVAL 3 DAY),
     DATE_SUB(@now, INTERVAL 3 DAY), @now);

-- ---------------------------------------------------------------------------
-- 12. carrier_reports — one incident, PRIVATE so it stays inside your company
-- ---------------------------------------------------------------------------

INSERT INTO carrier_reports
    (uuid, company_id, user_id, carrier_id, carrier_row_id, carrier_dot_number,
     carrier_legal_name, incident_date, origin_city, origin_state, origin_country,
     destination_city, destination_state, destination_country, incidents, comments,
     is_private, carrier_email, emailed_at, email_error, created_at, updated_at)
VALUES
    (UUID(), @company_id, @user_id, @dot_number, @dot_number, @dot_number,
     @legal_name, DATE(DATE_SUB(@now, INTERVAL 20 DAY)),
     'Dallas', 'TX', 'USA', 'Phoenix', 'AZ', 'USA',
     JSON_ARRAY('late-delivery'), 'Seed data — delivered 6 hours past the appointment window.',
     1, @email, NULL, NULL, DATE_SUB(@now, INTERVAL 20 DAY), @now);

-- ---------------------------------------------------------------------------
-- Check, then COMMIT
-- ---------------------------------------------------------------------------

SELECT @carrier_company_id AS carrier_company_id, @owner_id AS owner_carrier_user_id,
       @request_id AS connect_request_id, @eld_id AS eld_connection_id,
       @shipment_id AS shipment_id, @dot_number AS dot_number;

SELECT id, email, is_owner, must_change_password, two_factor_enabled
FROM carrier_users WHERE carrier_company_id = @carrier_company_id;

COMMIT;
-- ROLLBACK;

/*
| NOT SEEDED, on purpose
|
| carriers  — the FMCSA directory. App\Models\Carriers\Carrier reads the
|             `external_db` connection where `carriers` is a VIEW over
|             company_census_file: it is not writable, and MySQL will reject
|             an INSERT into it. Point @dot_number at a DOT that already
|             exists there instead. (The local `carriers` table left over from
|             the old schema is unused by the carrier lookups.)
|
| carrier_blockeds — blocking this DOT for your own company would hide the
|             carrier from the very screens you are seeding it for.
|
| Cleanup (run in the same order, it is FK-safe):
|
|   SET @dot := '3948123';
|   DELETE FROM carrier_reports          WHERE carrier_dot_number = @dot;
|   DELETE r FROM coi_insurance_responses r
|     JOIN coi_insurance_requests q ON q.id = r.coi_insurance_request_id
|    WHERE q.dot_number = @dot;
|   DELETE FROM coi_insurance_requests   WHERE dot_number = @dot;
|   DELETE FROM shipments                WHERE carrier_dot = @dot;   -- stops cascade
|   DELETE FROM drivers                  WHERE phone_e164 IN ('+15550142310','+15550142311');
|   DELETE FROM search_histories         WHERE carrier_id = @dot;
|   DELETE FROM carrier_shortlists       WHERE carrier_id = @dot;
|   DELETE FROM carrier_fleet_stats      WHERE dot_number = @dot;
|   UPDATE carrier_connect_requests SET eld_connection_id = NULL WHERE carrier_dot_number = @dot;
|   DELETE FROM eld_connections          WHERE terminal_connection_id = CONCAT('conn_fake_', @dot);  -- fleet cascades
|   DELETE FROM carrier_connect_requests WHERE carrier_dot_number = @dot;  -- answers + documents cascade
|   DELETE FROM model_has_roles WHERE model_type = 'App\\Models\\CarrierUser'
|     AND model_id IN (SELECT id FROM carrier_users WHERE dot_number = @dot);
|   DELETE FROM carrier_users            WHERE dot_number = @dot;
|   DELETE FROM carrier_companies        WHERE dot_number = @dot;
*/
