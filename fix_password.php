<?php
/**
 * Password Hash Fix Script
 * Generates correct bcrypt hash for admin123 password
 */

// Generate correct password hash for 'admin123'
$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Correct password hash for 'admin123':\n";
echo $hash . "\n\n";

// Test the hash
if (password_verify($password, $hash)) {
    echo "✅ Hash verification successful!\n";
} else {
    echo "❌ Hash verification failed!\n";
}

// Generate SQL to update the database
echo "\nSQL to fix the database:\n";
echo "UPDATE users SET password_hash = '$hash' WHERE email = 'admin@quailtymed.com';\n";
?>
