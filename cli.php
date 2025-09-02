#!/usr/bin/env php
<?php

/**
 * CLI tool for Snake Eyes Game database management
 * 
 * Usage:
 *   php cli.php reset        - Reset the database (delete all data)
 *   php cli.php vitals       - Check database vitals (record counts, etc.)
 *   php cli.php help         - Show help information
 */

// Database configuration
$dbPath = __DIR__ . '/game_scores.db';

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Get command line arguments
$args = $argv ?? [];
$command = $args[1] ?? 'help';

try {
    // Connect to database
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($command) {
        case 'reset':
            resetDatabase($pdo);
            break;
        case 'vitals':
            checkVitals($pdo);
            break;
        case 'help':
        default:
            showHelp();
            break;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Reset the database by deleting all records
 */
function resetDatabase($pdo) {
    echo "Resetting database...\n";
    
    // Delete all records from tables
    $tables = ['games', 'game_moves', 'scores'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("DELETE FROM $table");
        $stmt->execute();
        echo "Deleted all records from $table table\n";
    }
    
    echo "Database reset completed successfully!\n";
}

/**
 * Check database vitals and display information
 */
function checkVitals($pdo) {
    echo "Checking database vitals...\n\n";
    
    // Get table information
    $tables = ['games', 'game_moves', 'scores'];
    
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $table");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result['count'];
        
        echo "$table: $count records\n";
    }
    
    echo "\n";
    
    // Get top scores
    echo "Top 5 scores:\n";
    $stmt = $pdo->prepare("SELECT player_name, score FROM games ORDER BY score DESC LIMIT 5");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo "No scores recorded yet.\n";
    } else {
        foreach ($results as $index => $row) {
            echo ($index + 1) . ". " . $row['player_name'] . " - " . $row['score'] . "\n";
        }
    }
    
    echo "\n";
    
    // Database file information
    $dbFileSize = filesize(__DIR__ . '/game_scores.db');
    echo "Database file size: " . formatBytes($dbFileSize) . "\n";
    
    echo "Vitals check completed!\n";
}

/**
 * Format bytes to human readable format
 */
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}

/**
 * Show help information
 */
function showHelp() {
    echo "Snake Eyes Game CLI Tool\n";
    echo "========================\n\n";
    echo "Usage: php cli.php [command]\n\n";
    echo "Commands:\n";
    echo "  reset    - Reset the database (delete all data)\n";
    echo "  vitals   - Check database vitals (record counts, etc.)\n";
    echo "  help     - Show this help information\n\n";
    echo "Examples:\n";
    echo "  php cli.php reset\n";
    echo "  php cli.php vitals\n";
}