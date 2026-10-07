SET @name := 'RUNDLETT';

SELECT
    c.dot_number,
    (SELECT GROUP_CONCAT(DISTINCT a.docket_number ORDER BY a.docket_number SEPARATOR ', ')
       FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number) AS mc_numbers,
    c.legal_name,
    c.dba_name,
    c.phy_state,
    c.phy_city,
    CONCAT_WS('/',
        NULLIF(CONCAT('Common:',  (SELECT MAX(common_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)), 'Common:'),
        NULLIF(CONCAT('Contract:',(SELECT MAX(contract_stat) FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)), 'Contract:'),
        NULLIF(CONCAT('Broker:',  (SELECT MAX(broker_stat)   FROM carrier.carrier_authorities a WHERE a.dot_number = c.dot_number)), 'Broker:')
    )                                                                        AS authority,
    d.status_code                                                            AS usdot_status,
    c.nbr_power_unit                                                         AS power_units
FROM carrier.carriers c
LEFT JOIN carrier.carrier_details d ON d.dot_number = c.dot_number
WHERE c.legal_name LIKE CONCAT('%', @name, '%')
   OR c.dba_name   LIKE CONCAT('%', @name, '%')
ORDER BY (d.status_code = 'A') DESC, c.nbr_power_unit DESC
LIMIT 25;