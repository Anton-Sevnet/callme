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
        'connect_timeout' => 10000,
        'read_timeout' => 10000,
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
        'default_user_number' => '100',  // Default if not found
    ),
    
    // CRM source for lead creation
    'bx24_crm_source' => array(
        '8001' => 'CALL',
        '8002' => 'CALL',
        '8003' => 'CALL',
        'default_crm_source' => 'CALL',
    ),
    
    // Users who should see call cards
    'user_show_cards' => array(
        '101',
        '102',
        '103',
        '104',
        '105',
    ),
    
    // Debug mode (enable logging)
    'CallMeDEBUG' => true,
    
    // Event listener timeout (microseconds)
    'listener_timeout' => 100000,  // 0.1 second
);

