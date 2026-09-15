/*
| Verifies the seed from docs/seed-fake-carrier-promonkey.sql.
|
| Read-only — safe to run on production as often as you like.
| Set @dot to the same value you seeded with.
*/

SET @dot   := '3948123';
SET @email := 'deepak@promonkey.tech';

-- 1. One row per table, with what it SHOULD be. Anything showing 0 (or a
--    mismatch in the `expected` column) means that step did not land.

SELECT 'carrier_companies'        AS table_name, COUNT(*) AS rows_found, '1' AS expected FROM carrier_companies        WHERE dot_number = @dot
UNION ALL SELECT 'carrier_users',            COUNT(*), '2'  FROM carrier_users            WHERE dot_number = @dot
UNION ALL SELECT 'model_has_roles',          COUNT(*), '2'  FROM model_has_roles          WHERE model_type = 'App\\Models\\CarrierUser'
          AND model_id IN (SELECT id FROM carrier_users WHERE dot_number = @dot)
UNION ALL SELECT 'carrier_connect_requests', COUNT(*), '1'  FROM carrier_connect_requests WHERE carrier_dot_number = @dot
UNION ALL SELECT 'carrier_connect_documents',COUNT(*), '2'  FROM carrier_connect_documents d
          JOIN carrier_connect_requests r ON r.id = d.carrier_connect_request_id WHERE r.carrier_dot_number = @dot
UNION ALL SELECT 'carrier_connect_answers',  COUNT(*), '= your carrier_questions count' FROM carrier_connect_answers a
          JOIN carrier_connect_requests r ON r.id = a.carrier_connect_request_id WHERE r.carrier_dot_number = @dot
UNION ALL SELECT 'eld_connections',          COUNT(*), '1'  FROM eld_connections          WHERE terminal_connection_id = CONCAT('conn_fake_', @dot)
UNION ALL SELECT 'eld_vehicles',             COUNT(*), '3'  FROM eld_vehicles v
          JOIN eld_connections c ON c.id = v.eld_connection_id WHERE c.terminal_connection_id = CONCAT('conn_fake_', @dot)
UNION ALL SELECT 'eld_drivers',              COUNT(*), '2'  FROM eld_drivers dr
          JOIN eld_connections c ON c.id = dr.eld_connection_id WHERE c.terminal_connection_id = CONCAT('conn_fake_', @dot)
UNION ALL SELECT 'eld_hos_logs',             COUNT(*), '4'  FROM eld_hos_logs h
          JOIN eld_connections c ON c.id = h.eld_connection_id WHERE c.terminal_connection_id = CONCAT('conn_fake_', @dot)
UNION ALL SELECT 'eld_vehicle_locations',    COUNT(*), '3'  FROM eld_vehicle_locations l
          JOIN eld_connections c ON c.id = l.eld_connection_id WHERE c.terminal_connection_id = CONCAT('conn_fake_', @dot)
UNION ALL SELECT 'carrier_fleet_stats',      COUNT(*), '1'  FROM carrier_fleet_stats      WHERE dot_number = @dot
UNION ALL SELECT 'carrier_shortlists',       COUNT(*), '1'  FROM carrier_shortlists       WHERE carrier_id = @dot
UNION ALL SELECT 'search_histories',         COUNT(*), '1'  FROM search_histories         WHERE carrier_id = @dot
UNION ALL SELECT 'shipments',                COUNT(*), '1'  FROM shipments                WHERE carrier_dot = @dot
UNION ALL SELECT 'shipment_stops',           COUNT(*), '2'  FROM shipment_stops s
          JOIN shipments sh ON sh.id = s.shipment_id WHERE sh.carrier_dot = @dot
UNION ALL SELECT 'shipment_tracking_updates',COUNT(*), '1'  FROM shipment_tracking_updates t
          JOIN shipments sh ON sh.id = t.shipment_id WHERE sh.carrier_dot = @dot
UNION ALL SELECT 'drivers',                  COUNT(*), '2'  FROM drivers                  WHERE phone_e164 IN ('+15550142310','+15550142311')
UNION ALL SELECT 'coi_insurance_requests',   COUNT(*), '1'  FROM coi_insurance_requests   WHERE dot_number = @dot
UNION ALL SELECT 'coi_insurance_responses',  COUNT(*), '1'  FROM coi_insurance_responses  rs
          JOIN coi_insurance_requests rq ON rq.id = rs.coi_insurance_request_id WHERE rq.dot_number = @dot
UNION ALL SELECT 'carrier_reports',          COUNT(*), '1'  FROM carrier_reports          WHERE carrier_dot_number = @dot;

-- 2. The login itself. must_change_password and two_factor_enabled must both
--    be 0, or the portal will bounce you to a password change / email OTP.

SELECT cu.id, cu.email, cu.first_name, cu.is_owner,
       cu.must_change_password, cu.two_factor_enabled, cu.status,
       cu.email_verified_at, cc.legal_name, cc.dot_number,
       r.name AS role, r.guard_name
FROM carrier_users cu
JOIN carrier_companies cc ON cc.id = cu.carrier_company_id
LEFT JOIN model_has_roles mhr ON mhr.model_id = cu.id AND mhr.model_type = 'App\\Models\\CarrierUser'
LEFT JOIN roles r ON r.id = mhr.role_id
WHERE cc.dot_number = @dot;

-- 3. The onboarding, as the broker's Carrier Connect list will read it.
--    Every *_at below should be non-null for a "completed" request.

SELECT r.id, r.uuid, r.status, r.company_id, r.carrier_user_id,
       r.email_verified_at, r.mobile_verified_at, r.didit_status,
       r.stripe_verified_at, r.eld_connection_id, r.eld_connected_at,
       r.questionnaire_completed_at, r.documents_completed_at, r.signed_at,
       r.portal_account_provisioned_at, r.portal_account_email, r.portal_account_error
FROM carrier_connect_requests r
WHERE r.carrier_dot_number = @dot;

-- 4. Wiring that a plain row count would not catch:
--    the request must point at the ELD connection, and the connection back
--    at the request via external_id.

SELECT r.id AS request_id, r.eld_connection_id, c.id AS connection_id,
       c.external_id, c.status, c.sync_status, c.last_synced_at,
       (r.eld_connection_id = c.id)                AS link_ok,
       (c.external_id = CAST(r.id AS CHAR))        AS external_id_ok
FROM carrier_connect_requests r
JOIN eld_connections c ON c.terminal_connection_id = CONCAT('conn_fake_', @dot)
WHERE r.carrier_dot_number = @dot;

-- 5. Orphan check — anything here means a FK pointed at nothing that exists.

SELECT 'answers with no question' AS problem, COUNT(*) AS rows_found
FROM carrier_connect_answers a
LEFT JOIN carrier_questions q ON q.id = a.carrier_question_id
JOIN carrier_connect_requests r ON r.id = a.carrier_connect_request_id
WHERE r.carrier_dot_number = @dot AND q.id IS NULL
UNION ALL
SELECT 'carrier_user with no company', COUNT(*)
FROM carrier_users cu LEFT JOIN carrier_companies cc ON cc.id = cu.carrier_company_id
WHERE cu.dot_number = @dot AND cc.id IS NULL
UNION ALL
SELECT 'carrier_user with no role', COUNT(*)
FROM carrier_users cu
LEFT JOIN model_has_roles mhr ON mhr.model_id = cu.id AND mhr.model_type = 'App\\Models\\CarrierUser'
WHERE cu.dot_number = @dot AND mhr.role_id IS NULL
UNION ALL
SELECT 'request pointing at a missing company', COUNT(*)
FROM carrier_connect_requests r LEFT JOIN companies co ON co.id = r.company_id
WHERE r.carrier_dot_number = @dot AND co.id IS NULL;

-- 6. Does this DOT actually exist in the FMCSA census?
--    RUN THIS ONE ON THE EXTERNAL CARRIER DATABASE (external_db), not here.
--    No row = the broker-side search / trust-score / profile screens will be
--    empty for this carrier, however good the local rows look.
--
--    SELECT dot_number, legal_name, phy_city, phy_state
--    FROM carriers WHERE dot_number = 3948123;
