/*
| Repoints the "PROMONKEY LOGISTICS LLC" test carrier (DOT 9500001) at
| +919958260843, so the onboarding phone-verification OTP goes there.
|
| THIS IS TWO SCRIPTS. They run against two different databases:
|
|   Part A -> the EXTERNAL carrier database (shared, on EC2):
|       mysql -h 18.222.238.176 -u laravel -p carrier < this-file.sql
|
|   Part B -> the APP database, on whichever box you are fixing. On live that
|             is the server's own MySQL, so run it there:
|       mysql -u <user> -p <app_db>
|
| Part B alone is enough to change where the next OTP is sent. Part A is what
| stops it reverting: pressing "Connect" again for this carrier refills
| carrier_connect_requests.carrier_phone from carrier_census.telephone
| (CarrierConnectController::send, "'carrier_phone' => $carrier->telephone").
|
| Why the number is written in full +91 E.164 form, not as 9958260843:
| normalisePhone() returns anything starting with "+" untouched, and only
| stamps config('carrier_connect.default_dial_code') onto BARE 10-digit
| numbers. Live has that set to +1, so a bare 10-digit Indian number becomes
| +19958260843 and the code goes to a stranger in the US. Keep the +91.
|
| Everything the app reads on the external database is a VIEW, so Part A
| writes to the base tables underneath:
|
|     carriers, carrier_details   ->  company_census_file.phone
|     carrier_census              ->  sms_input_motor_carrier_census_information.telephone
|     carrier_authorities         ->  carrier_all_with_history.bus_telno
|
| Note carrier_all_with_history keys on the ZERO-PADDED dot number, 09500001.
|
| Verified values before this change (2026-09-02), all three: +918076734039.
*/

-- ===========================================================================
-- Part A -- external carrier database
-- ===========================================================================

START TRANSACTION;

UPDATE company_census_file
   SET phone = '+919958260843'
 WHERE dot_number = '9500001';

UPDATE sms_input_motor_carrier_census_information
   SET telephone = '+919958260843'
 WHERE dot_number = '9500001';

UPDATE carrier_all_with_history
   SET bus_telno = '+919958260843'
 WHERE dot_number = '09500001';

COMMIT;

-- Confirm. All three must read +919958260843.
SELECT 'company_census_file'      AS source, phone     AS phone FROM company_census_file                          WHERE dot_number = '9500001'
UNION ALL
SELECT 'carrier_census'           AS source, telephone AS phone FROM sms_input_motor_carrier_census_information   WHERE dot_number = '9500001'
UNION ALL
SELECT 'carrier_authorities'      AS source, bus_telno AS phone FROM carrier_all_with_history                     WHERE dot_number = '09500001';


-- ===========================================================================
-- Part B -- app database (run on the live server)
-- ===========================================================================

/*
UPDATE carrier_connect_requests
   SET carrier_phone = '+919958260843'
 WHERE carrier_dot_number = '9500001';

-- The seeded portal account and its shipments carry the same number. Update
-- them too if you want the carrier-portal login OTP and the driver-facing
-- shipment contact to match; skip if you only care about onboarding.
UPDATE carrier_companies SET phone = '+919958260843' WHERE dot_number = '9500001';
UPDATE shipments         SET carrier_phone = '+919958260843' WHERE carrier_dot = '9500001';

SELECT uuid, carrier_dot_number, carrier_legal_name, carrier_phone
  FROM carrier_connect_requests
 WHERE carrier_dot_number = '9500001';
*/


-- ===========================================================================
-- Rollback
-- ===========================================================================

/*
-- External database:
UPDATE company_census_file                        SET phone     = '+918076734039' WHERE dot_number = '9500001';
UPDATE sms_input_motor_carrier_census_information SET telephone = '+918076734039' WHERE dot_number = '9500001';
UPDATE carrier_all_with_history                   SET bus_telno = '+918076734039' WHERE dot_number = '09500001';

-- App database:
UPDATE carrier_connect_requests SET carrier_phone = '+918076734039' WHERE carrier_dot_number = '9500001';
*/
