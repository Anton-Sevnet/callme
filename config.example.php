<?php
/**
 * CallMe Configuration Example
 * 
 * Copy this file to config.php and fill in your settings
 * cp config.example.php config.php
 */

return array(
    // Asterisk AMI Settings
    'asterisk' => array(
        'host' => '127.0.0.1',      // Asterisk AMI host
        'port' => 5038,             // Asterisk AMI port
        'username' => 'admin',      // AMI username
        'secret' => 'your_secret',  // AMI password
        'connect_timeout' => 10,    // seconds
        'read_timeout' => 10000,    // milliseconds (≈10 seconds)
        'scheme' => 'tcp://'
    ),

    // Bitrix24 API Settings
    'bitrixApiUrl' => 'https://your-domain.bitrix24.ru/rest/1/your_webhook_key/',
    
    // Authorization token for incoming requests from Bitrix24
    'authToken' => 'your-auth-token-here',
    
    // Technology (SIP/PJSIP/etc)
    'tech' => 'SIP',
    
    // Asterisk context for outgoing calls
    'context' => 'from-internal',
    
    // External numbers to monitor (incoming calls)
    'extentions' => array(
        '8001',  // Example: your incoming line numbers
        '8002',
        '8003',
    ),
    
    // Responsible determination mode:
    // 'crm_responsible' - show call card to CRM responsible (if found)
    // 'static_mapping' - always use static mapping from 'bx24' below
    'responsible_mode' => 'crm_responsible',
    
    // Mapping: extension -> internal number
    // Used as fallback when:
    // - responsible_mode = 'static_mapping'
    // - or CRM entity not found
    // - or responsible has no internal number
    'bx24' => array(
        '8001' => '101',  // Extension 8001 -> Internal number 101
        '8002' => '102',
        '8003' => '103',
    ),
    
    // CRM source for lead creation
    'bx24_crm_source' => array(
        '8001' => 'CALL',
        '8002' => 'CALL',
        '8003' => 'CALL',
        'default_crm_source' => 'CALL',
    ),

    // ID of the list with trunk → source mapping (run php_applets/install_trunk_source_iblock.php). Adjust to your portal.
    'roi_source_iblock_id' => 17,
    
    // Users who should see call cards
    'user_show_cards' => array(
        '101',
        '102',
        '103',
        '104',
        '105',
    ),
    'fallback_responsible_user_id' => 1,
    
    // Event listener timeout (microseconds)
    'listener_timeout' => 50000,  // 0.05 second

    // Health-check configuration
    'healthCheckTimeout' => 5,   // seconds: cycle watchdog interval
    'pingIdleTimeout' => 30,     // seconds: idle period before PingAction
    'hold_timeout' => 60,        // seconds: max MusicOnHold duration before force hangup

    'ami_healthcheck_log' => array(
        'ping' => array('NOTICE' => true, 'DEBUG' => false),
        'watchdog' => array('NOTICE' => true, 'DEBUG' => false),
        'reconnect' => array('NOTICE' => true, 'DEBUG' => false),
    ),

    // Debug mode (enable logging)
    'CallMeDEBUG' => true,
    
    // Full event log (logs ALL events to full.log, very verbose)
    'enable_full_log' => false,
);

