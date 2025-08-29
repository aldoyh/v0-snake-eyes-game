<?php
// Set headers to allow CORS and define the response type as JSON
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// --- Database Configuration ---
// SQLite database file path (same as in index.php)
$dbPath = __DIR__ . '/../game_scores.db';

// --- Database Connection ---
try {
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create the tables if they don't exist (for compatibility)
    $pdo->exec("CREATE TABLE IF NOT EXISTS games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        player_name TEXT NOT NULL,
        score INTEGER NOT NULL,
        level INTEGER NOT NULL,
        game_duration INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        player TEXT NOT NULL,
        score INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// --- Fetch Top 10 Scores ---
try {
    // Try to fetch from new games table first
    $stmt = $pdo->prepare("SELECT id as game_id, player_name as player, score, level, game_duration, created_at FROM games ORDER BY score DESC LIMIT 10");
    $stmt->execute();
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If no games found, fall back to legacy scores table
    if (empty($games)) {
        $stmt = $pdo->prepare("SELECT player, score FROM scores ORDER BY score DESC LIMIT 10");
        $stmt->execute();
        $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($scores);
    } else {
        echo json_encode($games);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch scores: ' . $e->getMessage()]);
}
