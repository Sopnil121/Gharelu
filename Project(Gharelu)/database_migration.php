<?php
// database_migration.php - Run this once to set up the database

require_once 'config.php';

// Run migration
function migrateDatabase() {
    $conn = getDBConnection();
    
    // SQL to create contact_messages table
    $sql = "
    CREATE TABLE IF NOT EXISTS contact_messages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        admin_reply TEXT NULL,
        status ENUM('unread', 'read', 'replied') DEFAULT 'unread',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        replied_at TIMESTAMP NULL,
        PRIMARY KEY (id),
        INDEX idx_email (email),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    if ($conn->query($sql) === TRUE) {
        echo " Table 'contact_messages' created successfully!<br>";
        echo " Database setup complete!<br>";
        echo "<br><a href='index.php'>Go to Homepage</a>";
    } else {
        echo "Error creating table: " . $conn->error . "<br>";
    }
    
    $conn->close();
}

// Run the migration
migrateDatabase();
?>