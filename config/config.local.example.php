<?php
/**
 * Local Configuration Template - Cơ Khí
 * Copy this file to config.local.php and update with your credentials
 */

return [
    // Google OAuth Credentials
    'GOOGLE_CLIENT_ID' => 'your-client-id.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => 'your-client-secret',
    
    // Database Credentials
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'your_database_name',
    'DB_USER' => 'your_database_user',
    'DB_PASS' => 'your_database_password',
    
    // Encryption Key (generate with: openssl rand -base64 32)
    'ENCRYPTION_KEY' => 'your-random-encryption-key-here'
];
