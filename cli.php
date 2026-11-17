#!/usr/bin/env php
<?php

// Simple CLI for Snake Eyes Game
// Usage:
//   php cli.php                      # Start dev server (default)
//   php cli.php start                # Start dev server
//   php cli.php reset-db             # Reset SQLite database (drops and recreates)
//   php cli.php help                 # Show help

function println(string $msg = ''): void { echo $msg . "\n"; }

function show_help(): void {
    println('Snake Eyes Game - CLI');
    println('====================');
    println('Commands:');
    println('  start            Start development server');
    println('  reset-db         Reset SQLite database (game_scores.db)');
    println('  help             Show this help');
}

function get_db_path(): string {
    return __DIR__ . '/game_scores.db';
}

function recreate_schema(PDO $pdo): void {
    // Create tables (match public/index.php schema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        player_name TEXT NOT NULL,
        score INTEGER NOT NULL,
        level INTEGER NOT NULL,
        game_duration INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_moves (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER NOT NULL,
        move_sequence INTEGER NOT NULL,
        direction TEXT NOT NULL,
        timestamp_ms INTEGER NOT NULL,
        snake_length INTEGER NOT NULL,
        snake_head_x INTEGER NOT NULL,
        snake_head_y INTEGER NOT NULL,
        food_x INTEGER NOT NULL,
        food_y INTEGER NOT NULL,
        score INTEGER NOT NULL DEFAULT 0,
        level INTEGER NOT NULL DEFAULT 1,
        event_type TEXT DEFAULT NULL,
        event_data TEXT DEFAULT NULL,
        power_ups_data TEXT DEFAULT NULL,
        obstacles_data TEXT DEFAULT NULL,
        FOREIGN KEY (game_id) REFERENCES games (id)
    )");

    // Table for storing initial game state for proper replay initialization
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_initial_state (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER NOT NULL UNIQUE,
        initial_snake_x INTEGER NOT NULL,
        initial_snake_y INTEGER NOT NULL,
        initial_snake_direction TEXT NOT NULL,
        initial_food_x INTEGER NOT NULL,
        initial_food_y INTEGER NOT NULL,
        grid_cols INTEGER NOT NULL,
        grid_rows INTEGER NOT NULL,
        box_size INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games (id)
    )");

    // Legacy scores table used by leaderboard fallback
    $pdo->exec("CREATE TABLE IF NOT EXISTS scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        player TEXT NOT NULL,
        score INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

function reset_db(): int {
    $dbPath = get_db_path();
    println('Resetting database at: ' . $dbPath);

    // Remove existing DB file if present
    if (file_exists($dbPath)) {
        if (!@unlink($dbPath)) {
            println('Error: Unable to delete existing database file. Check permissions.');
            return 1;
        }
        println('Deleted existing database file.');
    } else {
        println('No existing database file found.');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        recreate_schema($pdo);
        println('Database recreated successfully.');
        return 0;
    } catch (Throwable $e) {
        println('Failed to recreate database: ' . $e->getMessage());
        return 1;
    }
}

function start_server(): int {
    println('Snake Eyes Game - Enhanced Edition');
    println('==================================');
    println('');
    println('Starting development server...');

    // If we previously recorded a port and it's active, reuse it
    $portFile = __DIR__ . '/.server_port';
    if (file_exists($portFile)) {
        $existingPort = trim(@file_get_contents($portFile));
        if ($existingPort !== '') {
            exec("lsof -i :$existingPort > /dev/null 2>&1", $chkOut, $chkRc);
            if ($chkRc === 0) {
                println("Server is already running on port $existingPort");
                println("Visit http://localhost:$existingPort to play the game");
                return 0;
            }
        }
    }

    // Make sure Run.sh is executable
    exec('chmod +x Run.sh');

    // Start the server in background
    exec('./Run.sh > /dev/null 2>&1 &');

    // Wait a moment for server to start
    sleep(2);

    // Try to detect the chosen port
    $port = null;
    if (file_exists($portFile)) {
        $port = trim(@file_get_contents($portFile));
    }
    if ($port === null || $port === '') {
        // Fallback: probe common ports 8000-8010
        for ($p = 8000; $p <= 8010; $p++) {
            exec("lsof -i :$p > /dev/null 2>&1", $out, $rc);
            if ($rc === 0) { $port = (string)$p; break; }
        }
    }

    println('Server started successfully!');
    if ($port) {
        println("Visit http://localhost:$port to play the game");
    } else {
        println('Visit http://localhost:8000 to play the game');
    }

    println('');
    println('Game Features:');
    println('  • Classic snake gameplay with modern enhancements');
    println('  • Power-up system (Speed, Shield, Slow Motion)');
    println('  • Dynamic obstacles');
    println('  • Progressive difficulty');
    println('  • Leaderboard with replay functionality');
    println('  • Full English/Arabic language support');
    println('  • Responsive design for all devices');

    println('');
    println('Controls:');
    println('  • Swipe/Tap on mobile to change direction');
    println('  • Arrow keys on desktop');

    println('');
    println('Press Ctrl+C to exit this script (server will continue running)');

    // Keep script running to show server output
    while (true) {
        sleep(1);
    }
}

// Entry point
$command = $argv[1] ?? 'start';
switch ($command) {
    case 'help':
    case '--help':
    case '-h':
        show_help();
        exit(0);
    case 'reset-db':
        exit(reset_db());
    case 'start':
    default:
        exit(start_server());
}
