<?php
// --- BACKEND LOGIC FOR SAVING SCORES ---

// This part of the script handles API requests from the game.
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    // Set headers to allow the request and define the response type as JSON.
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");

    // --- Database Configuration ---
    // SQLite database file path
    $dbPath = __DIR__ . '/../game_scores.db';

    // --- Database Connection ---
    try {
        $pdo = new PDO("sqlite:$dbPath");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create the updated database tables
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

        // Legacy support - keep old scores table for now
        $pdo->exec("CREATE TABLE IF NOT EXISTS scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player TEXT NOT NULL,
            score INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    // Handle different API actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents("php://input"));

        if (isset($data->action)) {
            switch ($data->action) {
                case 'save_game':
                    handleSaveGame($pdo, $data);
                    break;
                case 'save_move':
                    handleSaveMove($pdo, $data);
                    break;
                case 'save_initial_state':
                    handleSaveInitialState($pdo, $data);
                    break;
                default:
                    // Legacy score saving
                    handleLegacyScore($pdo, $data);
            }
        } else {
            // Legacy score saving
            handleLegacyScore($pdo, $data);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'get_replay':
                handleGetReplay($pdo, $_GET['game_id'] ?? null);
                break;
            case 'get_leaderboard':
                handleGetLeaderboard($pdo);
                break;
        }
    }

    exit;
}

// --- API Handler Functions ---
function handleSaveGame($pdo, $data)
{
    if (!isset($data->player_name) || !isset($data->score) || !isset($data->level) || !isset($data->game_duration)) {
        http_response_code(400);
        echo json_encode(['message' => 'Missing required game data']);
        return;
    }

    $playerName = htmlspecialchars(strip_tags($data->player_name));
    $score = (int) $data->score;
    $level = (int) $data->level;
    $gameDuration = (int) $data->game_duration;

    try {
        $stmt = $pdo->prepare("INSERT INTO games (player_name, score, level, game_duration) VALUES (:player_name, :score, :level, :game_duration)");
        $stmt->bindParam(':player_name', $playerName);
        $stmt->bindParam(':score', $score);
        $stmt->bindParam(':level', $level);
        $stmt->bindParam(':game_duration', $gameDuration);
        $stmt->execute();

        $gameId = $pdo->lastInsertId();

        // Also save to legacy scores table for compatibility
        $stmt = $pdo->prepare("INSERT INTO scores (player, score) VALUES (:player, :score)");
        $stmt->bindParam(':player', $playerName);
        $stmt->bindParam(':score', $score);
        $stmt->execute();

        echo json_encode(['message' => 'Game saved successfully', 'game_id' => $gameId]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to save game: ' . $e->getMessage()]);
    }
}

function handleSaveMove($pdo, $data)
{
    if (
        !isset($data->game_id) || !isset($data->move_sequence) || !isset($data->direction) ||
        !isset($data->timestamp_ms) || !isset($data->snake_length) ||
        !isset($data->food_x) || !isset($data->food_y) || !isset($data->snake_head_x) || !isset($data->snake_head_y)
    ) {
        http_response_code(400);
        echo json_encode(['message' => 'Missing required move data']);
        return;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO game_moves (
            game_id, move_sequence, direction, timestamp_ms, snake_length, 
            snake_head_x, snake_head_y, food_x, food_y, score, level, 
            event_type, event_data, power_ups_data, obstacles_data
        ) VALUES (
            :game_id, :move_sequence, :direction, :timestamp_ms, :snake_length,
            :snake_head_x, :snake_head_y, :food_x, :food_y, :score, :level,
            :event_type, :event_data, :power_ups_data, :obstacles_data
        )");
        
        $stmt->bindParam(':game_id', $data->game_id);
        $stmt->bindParam(':move_sequence', $data->move_sequence);
        $stmt->bindParam(':direction', $data->direction);
        $stmt->bindParam(':timestamp_ms', $data->timestamp_ms);
        $stmt->bindParam(':snake_length', $data->snake_length);
        $stmt->bindParam(':snake_head_x', $data->snake_head_x);
        $stmt->bindParam(':snake_head_y', $data->snake_head_y);
        $stmt->bindParam(':food_x', $data->food_x);
        $stmt->bindParam(':food_y', $data->food_y);
        $stmt->bindParam(':score', $data->score ?? 0);
        $stmt->bindParam(':level', $data->level ?? 1);
        $stmt->bindParam(':event_type', $data->event_type ?? null);
        $stmt->bindParam(':event_data', $data->event_data ?? null);
        $stmt->bindParam(':power_ups_data', $data->power_ups_data ?? null);
        $stmt->bindParam(':obstacles_data', $data->obstacles_data ?? null);
        $stmt->execute();

        echo json_encode(['message' => 'Move saved successfully']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to save move: ' . $e->getMessage()]);
    }
}

function handleLegacyScore($pdo, $data)
{
    if (!isset($data->score) || !is_numeric($data->score) || !isset($data->player) || empty(trim($data->player))) {
        http_response_code(400);
        echo json_encode(['message' => 'Player ID or Score is missing or invalid.']);
        return;
    }

    $score = (int) $data->score;
    $player = htmlspecialchars(strip_tags($data->player));

    try {
        $stmt = $pdo->prepare("INSERT INTO scores (player, score) VALUES (:player, :score)");
        $stmt->bindParam(':player', $player);
        $stmt->bindParam(':score', $score);
        $stmt->execute();

        echo json_encode(['message' => 'Score saved successfully.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to save score: ' . $e->getMessage()]);
    }
}

function handleSaveInitialState($pdo, $data)
{
    if (
        !isset($data->game_id) || !isset($data->initial_snake_x) || !isset($data->initial_snake_y) ||
        !isset($data->initial_snake_direction) || !isset($data->initial_food_x) || !isset($data->initial_food_y) ||
        !isset($data->grid_cols) || !isset($data->grid_rows) || !isset($data->box_size)
    ) {
        http_response_code(400);
        echo json_encode(['message' => 'Missing required initial state data']);
        return;
    }

    try {
        // Use INSERT OR REPLACE to handle potential duplicates
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO game_initial_state (
            game_id, initial_snake_x, initial_snake_y, initial_snake_direction,
            initial_food_x, initial_food_y, grid_cols, grid_rows, box_size
        ) VALUES (
            :game_id, :initial_snake_x, :initial_snake_y, :initial_snake_direction,
            :initial_food_x, :initial_food_y, :grid_cols, :grid_rows, :box_size
        )");
        
        $stmt->bindParam(':game_id', $data->game_id);
        $stmt->bindParam(':initial_snake_x', $data->initial_snake_x);
        $stmt->bindParam(':initial_snake_y', $data->initial_snake_y);
        $stmt->bindParam(':initial_snake_direction', $data->initial_snake_direction);
        $stmt->bindParam(':initial_food_x', $data->initial_food_x);
        $stmt->bindParam(':initial_food_y', $data->initial_food_y);
        $stmt->bindParam(':grid_cols', $data->grid_cols);
        $stmt->bindParam(':grid_rows', $data->grid_rows);
        $stmt->bindParam(':box_size', $data->box_size);
        $stmt->execute();

        echo json_encode(['message' => 'Initial state saved successfully']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to save initial state: ' . $e->getMessage()]);
    }
}
}

function handleGetReplay($pdo, $gameId)
{
    if (!$gameId) {
        http_response_code(400);
        echo json_encode(['message' => 'Game ID is required']);
        return;
    }

    try {
        // Get game info
        $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :game_id");
        $stmt->bindParam(':game_id', $gameId);
        $stmt->execute();
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            http_response_code(404);
            echo json_encode(['message' => 'Game not found']);
            return;
        }

        // Get all moves for this game
        $stmt = $pdo->prepare("SELECT * FROM game_moves WHERE game_id = :game_id ORDER BY move_sequence ASC");
        $stmt->bindParam(':game_id', $gameId);
        $stmt->execute();
        $moves = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get initial state for this game
        $stmt = $pdo->prepare("SELECT * FROM game_initial_state WHERE game_id = :game_id");
        $stmt->bindParam(':game_id', $gameId);
        $stmt->execute();
        $initialState = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'game' => $game,
            'moves' => $moves,
            'initial_state' => $initialState
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to fetch replay: ' . $e->getMessage()]);
    }
}

function handleGetLeaderboard($pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT g.id as game_id, g.player_name as player, g.score, g.level, g.game_duration, g.created_at
                              FROM games g ORDER BY g.score DESC LIMIT 10");
        $stmt->execute();
        $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($games);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch leaderboard: ' . $e->getMessage()]);
    }
}

// --- FRONTEND LOGIC (HTML, CSS, JS) ---
// If the request is a GET request, the script will continue and render the game's HTML.
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, maximum-scale=1.0, minimum-scale=1.0">
    <title>Snake Eyes - Modern Snake Game</title>
    <!-- External Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/p5.js/1.9.0/p5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Press+Start+2P&family=Tajawal:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary-green: #10b981;
            --secondary-green: #059669;
            --accent-blue: #3b82f6;
            --accent-purple: #8b5cf6;
            --accent-pink: #ec4899;
            --accent-amber: #f59e0b;
            --dark-bg: #0f172a;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --glass-shadow: rgba(0, 0, 0, 0.3);
        }
        
        body {
            background-image: url('snake-game-bg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            font-family: 'Poppins', sans-serif;
            overflow: hidden;
            touch-action: manipulation;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
            -webkit-tap-highlight-color: transparent;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Glass effect overlay for better readability */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, transparent 0%, rgba(0, 0, 0, 0.7) 100%);
            z-index: -1;
        }

        /* Arabic text styling */
        body[dir="rtl"] {
            font-family: 'Tajawal', sans-serif;
        }

        /* Ensure input fields work properly on all devices */
        input[type="text"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            border-radius: 0.75rem;
            /* Allow selecting/caret in inputs even if body disables selection */
            user-select: text;
            -webkit-user-select: text;
        }

        input[type="text"]:focus {
            outline: none;
        }

        .font-game {
            font-family: 'Press Start 2P', cursive;
        }

        /* Glass effect for main game UI */
        .main-game-ui {
            background: rgba(255, 255, 255, 0.05); /* More transparent */
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 
                0 8px 32px var(--glass-shadow),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .main-game-ui h1 {
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .main-game-ui .score-level > div {
            background: rgba(255, 255, 255, 0.05); /* More transparent */
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        /* Input field fonts - supports both LTR and RTL */
        .input-field {
            font-family: 'Poppins', sans-serif;
        }

        [dir="rtl"] .input-field {
            font-family: 'Tajawal', sans-serif;
        }

        .input-field:focus {
            outline: none;
            border: 2px solid var(--primary-green);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        #canvas-container {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 90vw;
            height: 75vh;
            max-width: 800px;
            max-height: 800px;
            margin: 0 auto;
            padding: 20px;
            border-radius: 25px;
            background: rgba(255, 255, 255, 0.05); /* More transparent */
            border: 1px solid var(--glass-border);
            box-shadow: 
                0 12px 40px var(--glass-shadow),
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(12px); /* Slightly less blur for better performance */
            -webkit-backdrop-filter: blur(12px);
        }

        canvas {
            border-radius: 18px;
            box-shadow: 
                0 8px 32px var(--glass-shadow),
                0 4px 16px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            touch-action: none;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            background: rgba(0, 0, 0, 0.1); /* More transparent background */
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Game controls buttons */
        .control-btn {
            background: rgba(255, 255, 255, 0.05); /* More transparent */
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        }

        .control-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            background: rgba(255, 255, 255, 0.1); /* More transparent on hover */
        }

        .control-btn:active {
            transform: translateY(0);
        }

        .control-btn.primary {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.8), rgba(5, 150, 105, 0.8)); /* More transparent */
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: white;
        }

        .control-btn.secondary {
            background: rgba(255, 255, 255, 0.05); /* More transparent */
            border: 1px solid var(--glass-border);
            color: white;
        }

        /* Overlay styling */
        .overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.8); /* More transparent */
            border-radius: 20px;
            padding: 2rem;
            text-align: center;
            z-index: 20;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }

        .overlay h2 {
            font-size: 2rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .overlay p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            max-width: 80%;
            line-height: 1.6;
        }

        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            #canvas-container {
                width: 95vw;
                height: 60vh;
                max-height: 60vh;
                padding: 15px;
                border-radius: 20px;
            }
            
            canvas {
                border-radius: 15px;
            }
            
            .main-game-ui {
                margin-bottom: 1rem;
                padding: 1rem;
            }
            
            .main-game-ui h1 {
                font-size: 2rem;
                text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
            }
            
            .main-game-ui .score-level {
                font-size: 1.25rem;
                gap: 1rem;
            }

            /* Responsive overlay adjustments */
            .overlay {
                padding: 1.5rem;
                border-radius: 15px;
            }

            .overlay h2 {
                font-size: 1.75rem;
                margin-bottom: 1rem;
            }

            .overlay p {
                font-size: 1rem;
                margin-bottom: 1.5rem;
                max-width: 90%;
            }

            #name-entry-overlay input {
                width: 90%;
                font-size: 1rem;
                padding: 0.75rem;
            }

            .control-btn {
                font-size: 0.9rem;
                padding: 0.75rem 1.25rem;
            }
        }

        @media (max-width: 480px) {
            #canvas-container {
                width: 98vw;
                height: 55vh;
                max-height: 55vh;
                padding: 12px;
                border-radius: 15px;
            }
            
            canvas {
                border-radius: 12px;
            }
            
            .main-game-ui h1 {
                font-size: 1.5rem;
                text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
            }
            
            .main-game-ui .score-level {
                font-size: 1rem;
                gap: 0.5rem;
            }

            /* Extra small screen overlay adjustments */
            .overlay {
                padding: 1rem;
                border-radius: 12px;
            }

            .overlay h2 {
                font-size: 1.5rem;
                margin-bottom: 0.75rem;
            }

            .overlay p {
                font-size: 0.9rem;
                margin-bottom: 1rem;
                max-width: 95%;
            }

            #name-entry-overlay input {
                width: 95%;
                font-size: 0.9rem;
                padding: 0.6rem;
            }

            .control-btn {
                font-size: 0.8rem;
                padding: 0.6rem 1rem;
            }

            #name-entry-overlay .flex {
                flex-direction: column;
                gap: 0.75rem;
            }
        }

        [dir="rtl"] {
            font-family: 'Tajawal', sans-serif;
        }

        /* Arabic-specific font weights and spacing */
        [dir="rtl"] .font-game {
            font-family: 'Tajawal', sans-serif;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        /* Better Arabic text rendering */
        [dir="rtl"] h1,
        [dir="rtl"] h2,
        [dir="rtl"] h3,
        [dir="rtl"] button,
        [dir="rtl"] .text-content {
            font-family: 'Tajawal', sans-serif;
            font-weight: 600;
        }

        /* Leaderboard Arabic styling */
        [dir="rtl"] #leaderboard-container {
            left: auto;
            right: 4px;
        }

        /* Language toggle positioning for RTL */
        [dir="rtl"] #lang-toggle-container {
            right: auto;
            left: 1rem;
        }

        #leaderboard-container {
            position: absolute;
            top: 4px;
            left: -260px;
            z-index: 20;
            background: rgba(15, 23, 42, 0.85);
            padding: 1rem;
            border-radius: 20px;
            width: 280px;
            max-width: 85vw;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease-in-out;
            transform: translateX(0);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        #leaderboard-container.expanded {
            left: 4px;
        }

        #leaderboard-toggle {
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 40;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem;
            border-radius: 12px;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        #leaderboard-toggle:hover {
            transform: scale(1.1);
            background: rgba(15, 23, 42, 0.8);
        }

        /* Retractable leaderboard on mobile */
        @media (max-width: 768px) {
            #leaderboard-container {
                position: fixed;
                top: 10px;
                left: -260px;
                width: 280px;
                max-width: 80vw;
                background: rgba(15, 23, 42, 0.95);
                border-radius: 0 0.75rem 0.75rem 0;
                padding: 0.75rem;
                z-index: 35;
                transform: translateX(0);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
            }
            
            #leaderboard-container.expanded {
                transform: translateX(260px);
            }
            
            #leaderboard-toggle {
                position: fixed;
                top: 10px;
                left: 10px;
                z-index: 40;
                background: rgba(15, 23, 42, 0.7);
                border: 1px solid rgba(255, 255, 255, 0.2);
                color: white;
                padding: 0.75rem;
                border-radius: 12px;
                font-size: 1.2rem;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
            }
            
            #leaderboard-toggle:hover {
                background: rgba(15, 23, 42, 0.8);
                transform: scale(1.1);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
            }
            
            #leaderboard-title {
                font-size: 1rem;
                margin-bottom: 0.5rem;
            }
            
            #leaderboard-list {
                font-size: 0.85rem;
                max-height: 200px;
                overflow-y: auto;
            }
        }

        @media (max-width: 480px) {
            #leaderboard-container {
                left: -240px;
                width: 250px;
                max-width: 75vw;
                padding: 0.5rem;
            }
            
            #leaderboard-container.expanded {
                transform: translateX(240px);
            }
            
            #leaderboard-title {
                font-size: 0.9rem;
            }
            
            #leaderboard-list {
                font-size: 0.8rem;
                max-height: 180px;
            }
        }

        /* Language toggle responsive positioning */
        @media (max-width: 768px) {
            #lang-toggle-container {
                top: 10px;
                right: 10px;
            }
            
            #lang-toggle {
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.2);
                padding: 0.5rem 0.75rem;
                font-size: 0.9rem;
                border-radius: 12px;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
                transition: all 0.3s ease;
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
            }

            #lang-toggle:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: scale(1.05);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            }
        }

        /* Glass effect overlays */
        .overlay-glass {
            background: rgba(15, 23, 42, 0.8) !important; /* Slightly more transparent */
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
        }

        .overlay-glass h2 {
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.7) !important;
        }

        .overlay-glass input {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            backdrop-filter: blur(8px) !important;
            -webkit-backdrop-filter: blur(8px) !important;
        }

        .overlay-glass .control-btn {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
            transition: all 0.3s ease !important;
            backdrop-filter: blur(8px) !important;
            -webkit-backdrop-filter: blur(8px) !important;
        }

        .overlay-glass .control-btn:hover {
            background: rgba(255, 255, 255, 0.2) !important;
            transform: scale(1.05) !important;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4) !important;
        }

        /* Progress bar for level */
        .progress-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-blue));
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Power-up indicators */
        .powerup-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin: 0 0.25rem;
            font-weight: bold;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
            background: rgba(255, 255, 255, 0.1); /* More transparent */
            backdrop-filter: blur(4px); /* Add blur effect */
            -webkit-backdrop-filter: blur(4px);
        }

        .powerup-speed { background: var(--accent-blue); }
        .powerup-shield { background: var(--accent-purple); }
        .powerup-slow { background: var(--accent-pink); }
        .powerup-duplicate { background: var(--accent-amber); }

        /* Game instructions */
        .instructions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
            width: 100%;
            max-width: 500px;
        }

        .instruction-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .instruction-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        /* Mobile leaderboard toggle */
        #mobile-leaderboard-toggle {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 30;
            background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
            color: white;
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            font-size: 1.5rem;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        #mobile-leaderboard-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 25px rgba(16, 185, 129, 0.6);
        }

        @media (max-width: 768px) {
            #mobile-leaderboard-toggle {
                display: block;
            }
        }

        /* Animation for new features */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* Custom scrollbar for leaderboard */
        #leaderboard-list::-webkit-scrollbar {
            width: 6px;
        }

        #leaderboard-list::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        #leaderboard-list::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 3px;
        }

        #leaderboard-list::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-green);
        }
    </style>
</head>

<body class="bg-gray-900 text-white flex flex-col items-center justify-center min-h-screen p-4" dir="ltr">

    <!-- Leaderboard Toggle Button -->
    <button id="leaderboard-toggle">
        📊
    </button>

    <!-- Mobile Leaderboard Toggle Button -->
    <button id="mobile-leaderboard-toggle">
        📊
    </button>

    <!-- Leaderboard Display -->
    <div id="leaderboard-container">
        <h3 id="leaderboard-title" class="text-lg font-game text-yellow-400 mb-2 text-center text-content">LEADERBOARD</h3>
        <ol id="leaderboard-list" class="list-decimal list-inside text-white space-y-1">
            <li class="opacity-50">Loading...</li>
        </ol>
    </div>

    <!-- Language Toggle Button -->
    <div class="absolute top-4 right-4 z-20" id="lang-toggle-container">
        <button id="lang-toggle"
            class="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg shadow-md transition-transform transform hover:scale-105">
            عربي
        </button>
    </div>

    <!-- Audio elements for game sounds -->
    <audio id="intro-splash-sound" preload="auto">
        <source src="intro-splash.mp3" type="audio/mpeg">
    </audio>
    <audio id="snake-eat-sound" preload="auto">
        <source src="snake-eat.mp3" type="audio/mpeg">
    </audio>
    <audio id="move-sound" preload="auto">
        <source src="move.mp3" type="audio/mpeg">
    </audio>
    <audio id="food-sound" preload="auto">
        <source src="food.mp3" type="audio/mpeg">
    </audio>

    <!-- Main Game UI -->
    <div class="w-full max-w-lg text-center mb-4 main-game-ui">
        <h1 id="title" class="text-4xl font-game text-green-400 text-content">SNAKE EYES</h1>
        <p id="subtitle" class="text-gray-400 mt-2 text-content">Swipe anywhere to control the snake</p>
        <div class="mt-4 text-2xl font-game flex justify-center items-center gap-8 score-level">
            <div class="text-content"><span id="score-label">SCORE</span>: <span id="score" class="text-yellow-400">0</span></div>
            <div class="text-content"><span id="level-label">LEVEL</span>: <span id="level" class="text-cyan-400">1</span></div>
            <div id="recording-indicator" class="text-content text-red-500 text-sm hidden">
                <span class="animate-pulse">●</span> <span id="recording-label">REC</span>
            </div>
        </div>
        <!-- Progress bar for next level -->
        <div class="progress-bar mt-2 mx-auto max-w-xs">
            <div id="progress-fill" class="progress-fill" style="width: 0%"></div>
        </div>
        <!-- Power-up indicators with tooltips explaining each powerup -->
        <div class="mt-2 flex justify-center">
            <div class="powerup-indicator powerup-speed" title="Speed Boost: Makes the snake move faster for a short time">⚡</div>
            <div class="powerup-indicator powerup-shield" title="Shield: Protects the snake from collisions for a short time">🛡️</div>
            <div class="powerup-indicator powerup-slow" title="Slow Motion: Slows down the snake for better control">🐢</div>
            <div class="powerup-indicator powerup-duplicate" title="Duplicate: Creates a copy of your snake that mirrors your movements">🪞</div>
        </div>
    </div>

    <!-- Canvas and Overlays Container -->
    <div id="canvas-container" class="relative">
        <!-- Name Entry Overlay -->
        <div id="name-entry-overlay"
            class="absolute inset-0 overlay-glass flex flex-col items-center justify-center rounded-lg text-center p-4 z-20">
            <h2 id="name-entry-title" class="text-4xl font-game text-yellow-400 mb-4 text-content">ENTER YOUR NAME</h2>
            <input type="text" id="player-name-input"
                class="input-field text-white text-xl p-4 rounded-lg mb-6 w-80 max-w-full text-center transition-all duration-200"
                placeholder="Your Name" maxlength="20" autocomplete="off" spellcheck="false" inputmode="text">
            <div class="flex flex-col sm:flex-row gap-4">
                <button id="confirm-name-button"
                    class="control-btn primary text-white font-bold py-3 px-8 rounded-lg shadow-lg transition-transform transform font-game text-lg disabled:opacity-50 disabled:hover:scale-100 text-content"
                    disabled>
                    CONTINUE
                </button>
                <button id="guest-button"
                    class="control-btn secondary text-white font-bold py-3 px-8 rounded-lg shadow-lg transition-transform transform font-game text-lg text-content">
                    PLAY AS GUEST
                </button>
            </div>
        </div>

        <div id="tutorial-overlay"
            class="absolute inset-0 overlay-glass flex flex-col items-center justify-center rounded-lg text-center p-4 z-10 hidden">
            <h2 id="tutorial-title" class="text-4xl font-game text-yellow-400 mb-4 text-content">HOW TO PLAY</h2>
            <div class="instructions">
                <div class="instruction-item">
                    <div class="instruction-icon">📱</div>
                    <p id="instruction-swipe" class="text-content">Swipe to move</p>
                </div>
                <div class="instruction-item">
                    <div class="instruction-icon">🍎</div>
                    <p id="instruction-eat" class="text-content">Eat food to grow</p>
                </div>
                <div class="instruction-item">
                    <div class="instruction-icon">⚡</div>
                    <p id="instruction-powerups" class="text-content">Collect power-ups for special abilities (Speed, Shield, Slow, Duplicate)</p>
                </div>
                <div class="instruction-item">
                    <div class="instruction-icon">💀</div>
                    <p id="instruction-avoid" class="text-content">Avoid walls and self</p>
                </div>
            </div>
            <button id="start-button"
                class="control-btn primary text-white font-bold py-3 px-8 rounded-lg shadow-lg transition-transform transform font-game text-lg text-content mt-4">
                START GAME
            </button>
            <div class="absolute bottom-4 text-gray-500 text-sm">
                <p id="credits">Created by HASAN ALDOY @aldoyh</p>
                <p id="copyright">&copy; <?php echo date("Y"); ?>. All Rights Reserved.</p>
            </div>
        </div>

        <div id="game-over"
            class="absolute inset-0 overlay-glass flex-col items-center justify-center rounded-lg text-center hidden">
            <h2 id="game-over-title" class="text-5xl font-game text-red-500 text-content">GAME OVER</h2>
            <p class="mt-4 text-xl text-gray-300 text-content"><span id="player-name-display" class="font-bold text-green-400"></span>, <span id="final-score-label">your score</span>: <span id="final-score"
                    class="font-bold text-yellow-300">0</span></p>
            <p class="mt-2 text-lg text-gray-400 text-content">Would you like to post your score or try again?</p>
            <div class="mt-6 flex gap-4">
                <button id="post-score-button"
                    class="control-btn primary text-white font-bold py-3 px-6 rounded-lg shadow-lg transition-transform transform font-game text-sm text-content">
                    POST SCORE
                </button>
                <button id="restart-button"
                    class="control-btn primary text-white font-bold py-3 px-6 rounded-lg shadow-lg transition-transform transform font-game text-sm text-content">
                    PLAY AGAIN
                </button>
                <button id="menu-button"
                    class="control-btn secondary text-white font-bold py-3 px-6 rounded-lg shadow-lg transition-transform transform font-game text-sm text-content">
                    MAIN MENU
                </button>
            </div>
        </div>

        <!-- Replay Overlay -->
        <div id="replay-overlay"
            class="absolute inset-0 overlay-glass flex flex-col items-center justify-center rounded-lg text-center p-4 z-15 hidden">
            <h2 id="replay-title" class="text-3xl font-game text-cyan-400 mb-4">REPLAY MODE</h2>
            <p id="replay-info" class="text-lg text-gray-300 mb-4">Replaying game...</p>
            <div class="flex gap-4 mb-4">
                <button id="replay-pause-button"
                    class="control-btn secondary text-white font-bold py-2 px-4 rounded-lg shadow-lg transition-transform transform font-game text-sm">
                    PAUSE
                </button>
                <button id="replay-speed-button"
                    class="control-btn secondary text-white font-bold py-2 px-4 rounded-lg shadow-lg transition-transform transform font-game text-sm">
                    SPEED: 1x
                </button>
                <button id="replay-restart-button"
                    class="control-btn secondary text-white font-bold py-2 px-4 rounded-lg shadow-lg transition-transform transform font-game text-sm">
                    RESTART
                </button>
            </div>
            <div class="w-full max-w-md flex items-center gap-3 mb-4">
                <span id="replay-time" class="text-sm text-gray-300 whitespace-nowrap">00:00 / 00:00</span>
                <input id="replay-seek" type="range" min="0" max="100" value="0" class="w-full" />
            </div>
            <button id="replay-exit-button"
                class="control-btn primary text-white font-bold py-2 px-4 rounded-lg shadow-lg transition-transform transform font-game text-sm">
                EXIT REPLAY
            </button>
        </div>
    </div>

    <script>
        // --- LANGUAGE & UI TEXT ---
        const translations = {
            en: {
                title: 'SNAKE EYES',
                subtitle: 'Swipe anywhere to control the snake',
                scoreLabel: 'SCORE',
                levelLabel: 'LEVEL',
                recordingLabel: 'REC',
                gameOverTitle: 'GAME OVER',
                finalScoreLabel: 'Your score',
                restartButton: 'PLAY AGAIN',
                menuButton: 'MAIN MENU',
                postScoreButton: 'POST SCORE',
                langToggle: 'عربي',
                tutorialTitle: 'HOW TO PLAY',
                instructionSwipe: 'Swipe to move',
                instructionEat: 'Eat food to grow',
                instructionPowerups: 'Collect power-ups for special abilities',
                instructionAvoid: 'Avoid walls and self',
                startButton: 'START GAME',
                credits: 'Created by HASAN ALDOY @aldoyh',
                copyright: '&copy; <?php echo date("Y"); ?>. All Rights Reserved.',
                leaderboardTitle: 'LEADERBOARD',
                nameEntryTitle: 'ENTER YOUR NAME',
                playerNamePlaceholder: 'Your Name',
                continueButton: 'CONTINUE',
                replayTitle: 'REPLAY MODE',
                replayInfo: 'Replaying game...',
                pauseButton: 'PAUSE',
                resumeButton: 'RESUME',
                speedButton: 'SPEED',
                exitReplayButton: 'EXIT REPLAY',
                watchReplayButton: 'WATCH REPLAY'
            },
            ar: {
                title: 'عيون الثعبان',
                subtitle: 'اسحب في أي مكان للتحكم في الثعبان',
                scoreLabel: 'النتيجة',
                levelLabel: 'المستوى',
                recordingLabel: 'تسجيل',
                gameOverTitle: 'انتهت اللعبة',
                finalScoreLabel: 'نتيجتك',
                restartButton: 'اللعب مجددًا',
                menuButton: 'القائمة الرئيسية',
                postScoreButton: 'نشر النتيجة',
                langToggle: 'English',
                tutorialTitle: 'كيفية اللعب',
                instructionSwipe: 'اسحب للتحرك',
                instructionEat: 'كل الطعام للنمو',
                instructionPowerups: 'اجمع الطاقات للحصول على قدرات خاصة (السرعة، الدرع، البطء، التكرار)',
                instructionAvoid: 'تجنب الجدران والذات',
                startButton: 'ابدأ اللعبة',
                credits: 'صنع بواسطة حسن الدوي @aldoyh',
                copyright: '&copy; <?php echo date("Y"); ?>. جميع الحقوق محفوظة.',
                leaderboardTitle: 'المتصدرون',
                nameEntryTitle: 'ادخل اسمك',
                playerNamePlaceholder: 'اسمك',
                continueButton: 'متابعة',
                replayTitle: 'وضع الإعادة',
                replayInfo: 'إعادة تشغيل اللعبة...',
                pauseButton: 'إيقاف',
                resumeButton: 'استئناف',
                speedButton: 'السرعة',
                exitReplayButton: 'إنهاء الإعادة',
                watchReplayButton: 'مشاهدة الإعادة'
            }
        };
        let currentLang = 'en';

        function setLanguage(lang) {
            currentLang = lang;
            const t = translations[lang];
            document.documentElement.lang = lang;
            document.body.dir = lang === 'ar' ? 'rtl' : 'ltr';

            // Update text content
            document.getElementById('title').textContent = t.title;
            document.getElementById('subtitle').textContent = t.subtitle;
            document.getElementById('score-label').textContent = t.scoreLabel;
            document.getElementById('level-label').textContent = t.levelLabel;
            document.getElementById('recording-label').textContent = t.recordingLabel;
            document.getElementById('game-over-title').textContent = t.gameOverTitle;
            document.getElementById('final-score-label').innerHTML = t.finalScoreLabel;
            document.getElementById('restart-button').textContent = t.restartButton;
            document.getElementById('menu-button').textContent = t.menuButton;
            document.getElementById('post-score-button').textContent = t.postScoreButton;
            document.getElementById('lang-toggle').textContent = t.langToggle;
            document.getElementById('tutorial-title').textContent = t.tutorialTitle;
            document.getElementById('instruction-swipe').textContent = t.instructionSwipe;
            document.getElementById('instruction-eat').textContent = t.instructionEat;
            document.getElementById('instruction-powerups').textContent = t.instructionPowerups;
            document.getElementById('instruction-avoid').textContent = t.instructionAvoid;
            document.getElementById('start-button').textContent = t.startButton;
            document.getElementById('credits').textContent = t.credits;
            document.getElementById('copyright').innerHTML = t.copyright;
            document.getElementById('leaderboard-title').textContent = t.leaderboardTitle;
            document.getElementById('name-entry-title').textContent = t.nameEntryTitle;
            document.getElementById('player-name-input').placeholder = t.playerNamePlaceholder;
            document.getElementById('confirm-name-button').textContent = t.continueButton;
            document.getElementById('replay-title').textContent = t.replayTitle;
            document.getElementById('replay-info').textContent = t.replayInfo;
            document.getElementById('replay-pause-button').textContent = t.pauseButton;
            document.getElementById('replay-exit-button').textContent = t.exitReplayButton;

            // Update input direction and text alignment for RTL
            const nameInput = document.getElementById('player-name-input');
            if (lang === 'ar') {
                nameInput.style.textAlign = 'center';
                nameInput.style.direction = 'rtl';
            } else {
                nameInput.style.textAlign = 'center';
                nameInput.style.direction = 'ltr';
            }
        }

        // --- GAME LOGIC (p5.js) ---
        const sketch = (p) => {
            // Grid cell size in pixels
            let boxSize = 20;
            let cols, rows, snake, food, powerUps = [];
            let score = 0,
                level = 1,
                direction = 'right';
            let isGameOver = false,
                touchStartX, touchStartY;
            let leaderboardData = [];
            let playerRank = Infinity;
            let playerName = '';
            let gameId = null;
            let moveSequence = 0;
            let gameMoves = [];
            let gameStartTime = 0;
            let particles = [];
            let obstacles = [];
            let duplicateSnake = null; // New variable for duplicate snake

            // Power-up system
            let activePowerUps = {
                speed: { active: false, timer: 0 },
                shield: { active: false, timer: 0 },
                slow: { active: false, timer: 0 },
                duplicate: { active: false, timer: 0 }
            };

            // Replay variables
            let isReplayMode = false;
            let replayData = null;
            let replayMoveIndex = 0;
            let replaySpeed = 1;
            let replayPaused = false;
            let replaySnake = null;
            let replayStartTime = null;
            let replayInitialTimestamp = null;
            let replayTotalDuration = 0;
            let replayKeydownHandler = null;

            // DOM Elements
            const scoreEl = document.getElementById('score');
            const levelEl = document.getElementById('level');
            const recordingIndicatorEl = document.getElementById('recording-indicator');
            const finalScoreEl = document.getElementById('final-score');
            const playerNameDisplay = document.getElementById('player-name-display');
            const gameOverEl = document.getElementById('game-over');
            const restartButton = document.getElementById('restart-button');
            const menuButton = document.getElementById('menu-button');
            const postScoreButton = document.getElementById('post-score-button');
            const tutorialOverlay = document.getElementById('tutorial-overlay');
            const startButton = document.getElementById('start-button');
            const langToggleButton = document.getElementById('lang-toggle');
            const leaderboardListEl = document.getElementById('leaderboard-list');
            const nameEntryOverlay = document.getElementById('name-entry-overlay');
            const playerNameInput = document.getElementById('player-name-input');
            const confirmNameButton = document.getElementById('confirm-name-button');
            const guestButton = document.getElementById('guest-button');
            const replayOverlay = document.getElementById('replay-overlay');
            const replayPauseButton = document.getElementById('replay-pause-button');
            const replaySpeedButton = document.getElementById('replay-speed-button');
            const replayRestartButton = document.getElementById('replay-restart-button');
            const replayExitButton = document.getElementById('replay-exit-button');
            const replayInfoEl = document.getElementById('replay-info');
            const replaySeek = document.getElementById('replay-seek');
            const replayTime = document.getElementById('replay-time');
            const leaderboardContainer = document.getElementById('leaderboard-container');
            const leaderboardToggle = document.getElementById('leaderboard-toggle');
            const mobileLeaderboardToggle = document.getElementById('mobile-leaderboard-toggle');
            const progressFill = document.getElementById('progress-fill');
            
            // Audio elements
            const introSplashSound = document.getElementById('intro-splash-sound');
            const snakeEatSound = document.getElementById('snake-eat-sound');
            const moveSound = document.getElementById('move-sound');
            const foodSound = document.getElementById('food-sound');

            // Mobile leaderboard state
            let leaderboardExpanded = false;

            // Setup mobile leaderboard toggle
            function setupMobileLeaderboard() {
                // Leaderboard is retracted by default on all devices
                leaderboardToggle.style.display = 'block';
                leaderboardContainer.classList.remove('expanded');
                leaderboardExpanded = false;
                
                // Add click listener if not already added
                leaderboardToggle.removeEventListener('click', toggleLeaderboard);
                leaderboardToggle.addEventListener('click', toggleLeaderboard);
                
                // Mobile toggle
                mobileLeaderboardToggle.removeEventListener('click', toggleLeaderboard);
                mobileLeaderboardToggle.addEventListener('click', toggleLeaderboard);
            }

            // Helper: check if any blocking overlay is visible or input is focused
            function isUIBlockingActive() {
                const isNameVisible = nameEntryOverlay && getComputedStyle(nameEntryOverlay).display !== 'none';
                const isTutorialVisible = tutorialOverlay && getComputedStyle(tutorialOverlay).display !== 'none';
                const isReplayVisible = replayOverlay && getComputedStyle(replayOverlay).display !== 'none';
                const isGameOverVisible = gameOverEl && getComputedStyle(gameOverEl).display !== 'none';
                const isInputFocused = document.activeElement === playerNameInput;
                return isNameVisible || isTutorialVisible || isReplayVisible || isGameOverVisible || isInputFocused;
            }

            function toggleLeaderboard() {
                leaderboardExpanded = !leaderboardExpanded;
                if (leaderboardExpanded) {
                    leaderboardContainer.classList.add('expanded');
                } else {
                    leaderboardContainer.classList.remove('expanded');
                }
            }

            // Hide/show leaderboard based on overlay state
            function setLeaderboardVisibility(show) {
                // Always start retracted, but allow showing when requested
                if (show) {
                    leaderboardToggle.style.display = 'block';
                    // Don't auto-expand, let user toggle manually
                } else {
                    leaderboardToggle.style.display = 'block';
                    leaderboardContainer.classList.remove('expanded');
                    leaderboardExpanded = false;
                }
            }

            // Handle window resize
            function handleResize() {
                setupMobileLeaderboard();
                const container = document.getElementById('canvas-container');
                p.resizeCanvas(container.offsetWidth, container.offsetHeight);
                cols = p.floor(p.width / boxSize);
                rows = p.floor(p.height / boxSize);
            }

            // Random name generation function
            function generateRandomName() {
                const adjectives = ['Cool', 'Fast', 'Smart', 'Brave', 'Clever', 'Swift', 'Bold', 'Wise', 'Keen', 'Ace'];
                const nouns = ['Player', 'Gamer', 'Snake', 'Champion', 'Master', 'Hero', 'Legend', 'Warrior', 'Pro', 'Expert'];
                const randomNumber = Math.floor(Math.random() * 1000);
                const randomAdjective = adjectives[Math.floor(Math.random() * adjectives.length)];
                const randomNoun = nouns[Math.floor(Math.random() * nouns.length)];
                return `${randomAdjective}${randomNoun}${randomNumber}`;
            }

            p.setup = () => {
                const container = document.getElementById('canvas-container');
                let canvas = p.createCanvas(container.offsetWidth, container.offsetHeight);
                canvas.parent('canvas-container');
                cols = p.floor(p.width / boxSize);
                rows = p.floor(p.height / boxSize);

                // Make sure input field is enabled
                playerNameInput.disabled = false;
                playerNameInput.readOnly = false;

                // Event listeners
                restartButton.addEventListener('click', resetGame);
                menuButton.addEventListener('click', returnToMenu);
                startButton.addEventListener('click', startGame);
                confirmNameButton.addEventListener('click', proceedToTutorial);
                guestButton.addEventListener('click', playAsGuest);
                
                // Prevent multiple clicks on post score button
                postScoreButton.addEventListener('click', function() {
                    this.disabled = true;
                    postScore();
                });

                // Multiple input event listeners for better compatibility
                playerNameInput.addEventListener('input', validateNameInput);
                playerNameInput.addEventListener('keyup', validateNameInput);
                playerNameInput.addEventListener('change', validateNameInput);
                playerNameInput.addEventListener('paste', (e) => {
                    setTimeout(() => validateNameInput(e), 10); // Delay to allow paste to complete
                });

                playerNameInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !confirmNameButton.disabled) {
                        proceedToTutorial();
                    }
                });

                // Add click event to the name entry overlay to focus input
                nameEntryOverlay.addEventListener('click', (e) => {
                    console.log('Overlay clicked, focusing input');
                    if (e.target === nameEntryOverlay || e.target === playerNameInput) {
                        playerNameInput.focus();
                    }
                });
                
                // Also add a focus event listener to the input to ensure it works
                playerNameInput.addEventListener('focus', (e) => {
                    console.log('Input focused');
                });
                
                // Add blur event listener to see if focus is lost
                playerNameInput.addEventListener('blur', (e) => {
                    console.log('Input blurred');
                });

                langToggleButton.addEventListener('click', () => setLanguage(currentLang === 'en' ? 'ar' : 'en'));

                // Replay controls
                replayPauseButton.addEventListener('click', toggleReplayPause);
                replaySpeedButton.addEventListener('click', cycleReplaySpeed);
                if (replayRestartButton) replayRestartButton.addEventListener('click', restartReplay);
                replayExitButton.addEventListener('click', exitReplay);
                const onSeekInput = (e) => {
                    const percent = parseInt(e.target.value, 10) || 0;
                    seekReplay(percent);
                };
                if (replaySeek) replaySeek.addEventListener('input', onSeekInput);

                // Setup mobile leaderboard
                setupMobileLeaderboard();
                window.addEventListener('resize', handleResize);

                p.noLoop();
                setupNameEntry();
                fetchLeaderboard();
                setInterval(fetchLeaderboard, 10000);

                // Debug: Log input element status
                console.log('Input element found:', !!playerNameInput);
                console.log('Input element details:', {
                    id: playerNameInput?.id,
                    disabled: playerNameInput?.disabled,
                    readOnly: playerNameInput?.readOnly,
                    style: playerNameInput?.style.cssText
                });
            };

            p.windowResized = () => {
                handleResize();
            };

            p.draw = () => {
                // Clear canvas to show the blurred background
                p.clear();
                if (isReplayMode) {
                    handleReplayMode();
                    if (replaySnake) replaySnake.show();
                } else {
                    if (!isGameOver) {
                        snake.update();
                        snake.checkCollision();
                        // Update duplicate snake if active
                        if (duplicateSnake) {
                            // Mirror the movement of the original snake
                            duplicateSnake.xdir = snake.xdir;
                            duplicateSnake.ydir = snake.ydir;
                            duplicateSnake.update();
                            // Check collision for duplicate snake (but don't trigger game over)
                            duplicateSnake.checkCollisionNoGameOver();
                        }
                    }
                    snake.show();
                    // Show duplicate snake if active
                    if (duplicateSnake) {
                        duplicateSnake.show(true); // Pass true to indicate it's a duplicate
                    }
                }
                
                // Draw obstacles
                for (let obs of obstacles) {
                    p.fill(100, 100, 150);
                    p.noStroke();
                    p.rect(obs.x, obs.y, boxSize, boxSize, 6);
                }
                
                // Draw power-ups
                for (let i = powerUps.length - 1; i >= 0; i--) {
                    powerUps[i].show();
                }
                
                if (food) {
                    p.fill(255, 82, 82);
                    p.noStroke();
                    p.push();
                    p.translate(food.x + boxSize / 2, food.y + boxSize / 2);
                    p.scale(food.scale || 1);
                    p.rectMode(p.CENTER);
                    p.rect(0, 0, boxSize, boxSize, 4);
                    p.pop();
                }
                // Update and draw particles
                for (let i = particles.length - 1; i >= 0; i--) {
                    particles[i].update();
                    particles[i].show();
                    if (particles[i].isFinished()) {
                        particles.splice(i, 1);
                    }
                }
                if (!isReplayMode && snake && snake.eat(food)) {
                    // Log food eaten event
                    logGameEvent('food_eaten', {
                        position: { x: food.x / boxSize, y: food.y / boxSize },
                        score_before: score,
                        score_after: score + 1
                    });
                    
                    createParticles(food.x, food.y);
                    placeFood();
                    // Play snake eat sound
                    if (snakeEatSound) {
                        snakeEatSound.currentTime = 0;
                        snakeEatSound.play().catch(e => {});
                    }
                    // Play food sound
                    if (foodSound) {
                        foodSound.currentTime = 0;
                        foodSound.play().catch(e => {});
                    }
                    score++;
                    // Animate score with GSAP
                    gsap.to(scoreEl, {
                        duration: 0.5,
                        innerHTML: score,
                        snap: { innerHTML: 1 },
                        ease: "power2.out"
                    });
                    updateProgress();
                    checkRankAndConfetti();
                    if (score > 0 && score % 5 === 0) {
                        const oldLevel = level;
                        level++;
                        
                        // Log level up event
                        logGameEvent('level_up', {
                            old_level: oldLevel,
                            new_level: level,
                            score: score
                        });
                        
                        // Animate level with GSAP
                        gsap.to(levelEl, {
                            duration: 0.5,
                            innerHTML: level,
                            snap: { innerHTML: 1 },
                            ease: "power2.out"
                        });
                        p.frameRate(10 + level * 2);
                        // Add obstacle every 2 levels
                        if (level % 2 === 0) {
                            addObstacle();
                        }
                        // Add power-up every 3 levels
                        if (level % 3 === 0) {
                            addPowerUp();
                        }
                    }
                }
                
                // Check for power-up collection
                if (!isReplayMode && snake) {
                    for (let i = powerUps.length - 1; i >= 0; i--) {
                        if (snake.eat(powerUps[i].pos)) {
                            const powerUpType = powerUps[i].type;
                            const powerUpPos = { x: powerUps[i].pos.x / boxSize, y: powerUps[i].pos.y / boxSize };
                            
                            // Log power-up collection event
                            logGameEvent('power_up_collected', {
                                type: powerUpType,
                                position: powerUpPos
                            });
                            
                            activatePowerUp(powerUpType);
                            powerUps.splice(i, 1);
                            // Play special sound for powerup collection
                            if (foodSound) {
                                foodSound.currentTime = 0;
                                foodSound.play().catch(e => {});
                            }
                            break;
                        }
                    }
                }
                
                // Check if duplicate snake eats food
                if (!isReplayMode && duplicateSnake) {
                    if (duplicateSnake.eat(food)) {
                        // Log duplicate snake food eaten event
                        logGameEvent('food_eaten', {
                            position: { x: food.x / boxSize, y: food.y / boxSize },
                            score_before: score,
                            score_after: score + 1,
                            eaten_by: 'duplicate_snake'
                        });
                        
                        // If duplicate snake eats food, grow the original snake too
                        snake.grow();
                        createParticles(food.x, food.y);
                        placeFood();
                        // Play snake eat sound
                        if (snakeEatSound) {
                            snakeEatSound.currentTime = 0;
                            snakeEatSound.play().catch(e => {});
                        }
                        // Play food sound
                        if (foodSound) {
                            foodSound.currentTime = 0;
                            foodSound.play().catch(e => {});
                        }
                        score++;
                        // Animate score with GSAP
                        gsap.to(scoreEl, {
                            duration: 0.5,
                            innerHTML: score,
                            snap: { innerHTML: 1 },
                            ease: "power2.out"
                        });
                        updateProgress();
                        checkRankAndConfetti();
                        if (score > 0 && score % 5 === 0) {
                            level++;
                            levelEl.textContent = level;
                            p.frameRate(10 + level * 2);
                            // Add obstacle every 2 levels
                            if (level % 2 === 0) {
                                addObstacle();
                            }
                            // Add power-up every 3 levels
                            if (level % 3 === 0) {
                                addPowerUp();
                            }
                        }
                    }
                }
                
                // Update power-up timers
                updatePowerUps();
            };

            p.touchStarted = () => {
                // Don't block default when overlays are visible or input is focused
                if (isUIBlockingActive()) return;
                if (!isGameOver && !isReplayMode) {
                    touchStartX = p.mouseX;
                    touchStartY = p.mouseY;
                    return false; // prevent scrolling only when we handle the gesture
                }
            }

            p.touchEnded = () => {
                // Allow default when overlays are visible (e.g., focusing inputs)
                if (isUIBlockingActive()) return;
                if (!isGameOver && !isReplayMode) {
                    const dx = p.mouseX - touchStartX;
                    const dy = p.mouseY - touchStartY;
                    let newDirection = direction;

                    if (Math.abs(dx) > Math.abs(dy)) {
                        if (dx > 0 && direction !== 'left') {
                            newDirection = 'right';
                        } else if (dx < 0 && direction !== 'right') {
                            newDirection = 'left';
                        }
                    } else {
                        if (dy > 0 && direction !== 'up') {
                            newDirection = 'down';
                        } else if (dy < 0 && direction !== 'down') {
                            newDirection = 'up';
                        }
                    }

                    if (newDirection !== direction) {
                        direction = newDirection;
                        snake.setDir(direction);
                        logMove(direction);
                    }
                    return false; // only prevent default when we process a swipe
                }
            }

            p.keyPressed = () => {
                // If typing in an input or overlays visible, don't handle arrows
                if (isUIBlockingActive()) return;
                if (!isReplayMode) {
                    let newDirection = direction;

                    if (p.keyCode === p.UP_ARROW && direction !== 'down') {
                        newDirection = 'up';
                    } else if (p.keyCode === p.DOWN_ARROW && direction !== 'up') {
                        newDirection = 'down';
                    } else if (p.keyCode === p.RIGHT_ARROW && direction !== 'left') {
                        newDirection = 'right';
                    } else if (p.keyCode === p.LEFT_ARROW && direction !== 'right') {
                        newDirection = 'left';
                    }

                    if (newDirection !== direction) {
                        direction = newDirection;
                        snake.setDir(direction);
                        logMove(direction);
                    }
                }
            }

            // --- NEW FUNCTIONS FOR ENHANCED FEATURES ---

            function setupNameEntry() {
                console.log('Setting up name entry');
                
                // Clear any previous input
                playerNameInput.value = '';
                confirmNameButton.disabled = true;

                // Make sure input is enabled
                playerNameInput.disabled = false;
                playerNameInput.readOnly = false;
                playerNameInput.tabIndex = 0; // Ensure it's focusable

                // Hide leaderboard on mobile during name entry
                setLeaderboardVisibility(false);

                // Play intro splash sound
                if (introSplashSound) {
                    introSplashSound.currentTime = 0;
                    introSplashSound.play().catch(e => console.log("Sound play prevented by browser:", e));
                }

                // Ensure the overlay is visible first
                nameEntryOverlay.style.display = 'flex';
                
                // Remove any existing GSAP animations on the overlay
                gsap.killTweensOf("#name-entry-overlay");
                gsap.killTweensOf(nameEntryOverlay);
                
                // Use GSAP timeline for entrance animation
                const tl = createPanelEntranceTimeline(nameEntryOverlay);
                tl.eventCallback("onStart", () => {
                    console.log('Animation started, setting focus');
                    // Focus the input field after a short delay to ensure it's visible
                    setTimeout(() => {
                        console.log('Attempting to focus input');
                        // Check if the input is visible before focusing
                        const rect = playerNameInput.getBoundingClientRect();
                        const isVisible = rect.top >= 0 && rect.left >= 0 && 
                                         rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) && 
                                         rect.right <= (window.innerWidth || document.documentElement.clientWidth);
                        
                        console.log('Input visibility:', isVisible, 'Rect:', rect);
                        
                        playerNameInput.focus();
                        playerNameInput.select(); // Select any existing text
                        console.log('Input focused:', document.activeElement === playerNameInput);
                        
                        // Try again after a longer delay to ensure it works
                        setTimeout(() => {
                            if (document.activeElement !== playerNameInput) {
                                console.log('Re-attempting focus');
                                playerNameInput.focus();
                                playerNameInput.select();
                                console.log('Input focused after re-attempt:', document.activeElement === playerNameInput);
                            }
                        }, 200);
                    }, 100);
                });
                tl.play();
            }

            function validateNameInput(e) {
                console.log('Input event triggered:', e?.type);
                const name = playerNameInput.value.trim();
                const isValid = name.length >= 2;
                confirmNameButton.disabled = !isValid;
                
                console.log('Name:', name, 'Valid:', isValid, 'Button disabled:', confirmNameButton.disabled);

                // Visual feedback
                if (name.length > 0) {
                    if (isValid) {
                        playerNameInput.style.borderColor = '#10b981'; // green
                        playerNameInput.style.backgroundColor = 'rgba(16, 185, 129, 0.1)'; // lighter green
                    } else {
                        playerNameInput.style.borderColor = '#f59e0b'; // yellow/orange
                        playerNameInput.style.backgroundColor = 'rgba(245, 158, 11, 0.1)'; // darker yellow
                    }
                } else {
                    playerNameInput.style.borderColor = 'rgba(255, 255, 255, 0.2)'; // default
                    playerNameInput.style.backgroundColor = 'rgba(255, 255, 255, 0.1)'; // default
                }

                console.log('Name validation:', {
                    name,
                    length: name.length,
                    isValid
                });
            }

            function playAsGuest() {
                playerName = generateRandomName();
                gsap.killTweensOf("#name-entry-overlay");
                
                // Use GSAP timeline for exit animation
                const exitTl = createPanelExitTimeline(nameEntryOverlay);
                exitTl.eventCallback("onComplete", () => {
                    nameEntryOverlay.style.display = 'none';
                    tutorialOverlay.style.display = 'flex';
                    setupTutorial();
                });
                exitTl.play();
            }

            function proceedToTutorial() {
                playerName = playerNameInput.value.trim();
                if (playerName.length < 2) return;

                gsap.killTweensOf("#name-entry-overlay");
                
                // Use GSAP timeline for exit animation
                const exitTl = createPanelExitTimeline(nameEntryOverlay);
                exitTl.eventCallback("onComplete", () => {
                    nameEntryOverlay.style.display = 'none';
                    tutorialOverlay.style.display = 'flex';
                    setupTutorial();
                });
                exitTl.play();
            }

            function logMove(newDirection) {
                if (!gameId || isReplayMode) return;
                
                // Get current snake head position
                const head = snake.body[0];
                
                // Serialize power-ups data
                const powerUpsData = powerUps.map(pu => ({
                    x: pu.pos.x / boxSize,
                    y: pu.pos.y / boxSize,
                    type: pu.type
                }));
                
                // Serialize obstacles data
                const obstaclesData = obstacles.map(obs => ({
                    x: obs.x / boxSize,
                    y: obs.y / boxSize
                }));
                
                const move = {
                    game_id: gameId,
                    move_sequence: moveSequence++,
                    direction: newDirection,
                    timestamp_ms: Date.now() - gameStartTime,
                    snake_length: snake.body.length,
                    snake_head_x: head.x / boxSize,
                    snake_head_y: head.y / boxSize,
                    food_x: food ? food.x / boxSize : 0,
                    food_y: food ? food.y / boxSize : 0,
                    score: score,
                    level: level,
                    event_type: null, // Will be set for special events like food eaten, power-up collected
                    event_data: null,
                    power_ups_data: JSON.stringify(powerUpsData),
                    obstacles_data: JSON.stringify(obstaclesData)
                };
                
                gameMoves.push(move);
                
                // Play move sound
                if (moveSound) {
                    moveSound.currentTime = 0;
                    moveSound.play().catch(e => {});
                }
                
                // Save move to backend (async, no await to avoid blocking)
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'save_move',
                        ...move
                    })
                }).catch(error => console.error('Failed to save move:', error));
            }

            function logGameEvent(eventType, eventData = null) {
                if (!gameId || isReplayMode) return;
                
                // Get current snake head position
                const head = snake.body[0];
                
                // Serialize power-ups data
                const powerUpsData = powerUps.map(pu => ({
                    x: pu.pos.x / boxSize,
                    y: pu.pos.y / boxSize,
                    type: pu.type
                }));
                
                // Serialize obstacles data
                const obstaclesData = obstacles.map(obs => ({
                    x: obs.x / boxSize,
                    y: obs.y / boxSize
                }));
                
                const move = {
                    game_id: gameId,
                    move_sequence: moveSequence++,
                    direction: snake.xdir === 1 ? 'right' : snake.xdir === -1 ? 'left' : snake.ydir === 1 ? 'down' : 'up',
                    timestamp_ms: Date.now() - gameStartTime,
                    snake_length: snake.body.length,
                    snake_head_x: head.x / boxSize,
                    snake_head_y: head.y / boxSize,
                    food_x: food ? food.x / boxSize : 0,
                    food_y: food ? food.y / boxSize : 0,
                    score: score,
                    level: level,
                    event_type: eventType,
                    event_data: eventData ? JSON.stringify(eventData) : null,
                    power_ups_data: JSON.stringify(powerUpsData),
                    obstacles_data: JSON.stringify(obstaclesData)
                };
                
                gameMoves.push(move);
                
                // Save event to backend (async, no await to avoid blocking)
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'save_move',
                        ...move
                    })
                }).catch(error => console.error('Failed to save event:', error));
            }

            async function saveGameData() {
                if (!playerName) return;

                const gameData = {
                    action: 'save_game',
                    player_name: playerName,
                    score: score,
                    level: level,
                    game_duration: Date.now() - gameStartTime
                };

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(gameData)
                    });

                    if (response.ok) {
                        const result = await response.json();
                        gameId = result.game_id;
                        console.log('Game saved with ID:', gameId);
                        
                        // Save initial game state for proper replay
                        await saveInitialGameState();
                    }
                } catch (error) {
                    console.error('Failed to save game:', error);
                }
            }

            async function saveInitialGameState() {
                if (!gameId || !snake || !food) return;
                
                const head = snake.body[0];
                const direction = snake.xdir === 1 ? 'right' : snake.xdir === -1 ? 'left' : snake.ydir === 1 ? 'down' : 'up';
                
                const initialState = {
                    action: 'save_initial_state',
                    game_id: gameId,
                    initial_snake_x: head.x / boxSize,
                    initial_snake_y: head.y / boxSize,
                    initial_snake_direction: direction,
                    initial_food_x: food.x / boxSize,
                    initial_food_y: food.y / boxSize,
                    grid_cols: cols,
                    grid_rows: rows,
                    box_size: boxSize
                };

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(initialState)
                    });

                    if (response.ok) {
                        console.log('Initial game state saved');
                    }
                } catch (error) {
                    console.error('Failed to save initial state:', error);
                }
            }

            async function startReplay(gameIdToReplay) {
                try {
                    const response = await fetch(`?action=get_replay&game_id=${gameIdToReplay}`);
                    if (!response.ok) throw new Error('Failed to fetch replay data');

                    replayData = await response.json();
                    
                    // Validate replay data
                    if (!replayData.game || !replayData.moves || replayData.moves.length === 0) {
                        throw new Error('Invalid replay data');
                    }
                    
                    isReplayMode = true;
                    // Hide recording indicator during replay
                    hideRecordingIndicator();
                    replayMoveIndex = 0;
                    replayPaused = false;
                    replaySpeed = 1;
                    replayStartTime = null;
                    replayInitialTimestamp = null;

                    // Hide game UI and show replay UI
                    replayOverlay.style.display = 'flex';
                    const totalMs = (replayData.moves[replayData.moves.length - 1].timestamp_ms - replayData.moves[0].timestamp_ms) || 0;
                    replayTotalDuration = Math.max(0, totalMs);
                    replayInfoEl.textContent = `Replaying ${replayData.game.player_name}'s game (Score: ${replayData.game.score})`;
                    if (replayTime) replayTime.textContent = '00:00 / ' + msToMMSS(replayTotalDuration);

                    // Initialize replay snake with correct position and length
                    replaySnake = new Snake();
                    powerUps = [];
                    obstacles = [];
                    
                    if (replayData.initial_state) {
                        // Use stored initial state for accurate replay
                        const initialState = replayData.initial_state;
                        const startX = initialState.initial_snake_x * initialState.box_size;
                        const startY = initialState.initial_snake_y * initialState.box_size;
                        
                        replaySnake.body = [p.createVector(startX, startY)];
                        
                        // Set initial direction
                        switch(initialState.initial_snake_direction) {
                            case 'right': replaySnake.xdir = 1; replaySnake.ydir = 0; break;
                            case 'left': replaySnake.xdir = -1; replaySnake.ydir = 0; break;
                            case 'up': replaySnake.xdir = 0; replaySnake.ydir = -1; break;
                            case 'down': replaySnake.xdir = 0; replaySnake.ydir = 1; break;
                        }
                        
                        // Set initial food position
                        food = p.createVector(
                            initialState.initial_food_x * initialState.box_size, 
                            initialState.initial_food_y * initialState.box_size
                        );
                        food.scale = 1;
                    } else if (replayData.moves && replayData.moves.length > 0) {
                        // Fallback to first move data if no initial state
                        const firstMove = replayData.moves[0];
                        replaySnake.body = [p.createVector(
                            firstMove.snake_head_x * boxSize, 
                            firstMove.snake_head_y * boxSize
                        )];
                        replaySnake.xdir = 1;
                        replaySnake.ydir = 0;
                        
                        // If the first move has a different length, adjust the snake
                        while (replaySnake.body.length < firstMove.snake_length) {
                            replaySnake.grow();
                        }
                        
                        // Set food position from first move
                        food = p.createVector(firstMove.food_x * boxSize, firstMove.food_y * boxSize);
                        food.scale = 1;
                    }

                    p.loop();
                    p.frameRate(30); // smooth draw; timing via timestamps

                    // Keyboard shortcuts for replay controls
                    replayKeydownHandler = (ev) => {
                        if (!isReplayMode) return;
                        if (ev.key === ' ') { // Space to pause/resume
                            ev.preventDefault();
                            toggleReplayPause();
                        } else if (ev.key.toLowerCase() === 's') { // S to change speed
                            ev.preventDefault();
                            cycleReplaySpeed();
                        } else if (ev.key === 'Escape') { // Esc to exit
                            ev.preventDefault();
                            exitReplay();
                        } else if (ev.key === 'ArrowLeft') { // Step back
                            ev.preventDefault();
                            stepReplay(-1);
                        } else if (ev.key === 'ArrowRight') { // Step forward
                            ev.preventDefault();
                            stepReplay(1);
                        }
                    };
                    document.addEventListener('keydown', replayKeydownHandler);
                } catch (error) {
                    console.error('Failed to start replay:', error);
                    alert('Failed to load replay data: ' + error.message);
                    // Exit replay mode on error
                    isReplayMode = false;
                    replayOverlay.style.display = 'none';
                    if (p.isLooping()) {
                        p.noLoop();
                    }
                }
            }

            function handleReplayMode() {
                try {
                    if (replayPaused || !replayData || replayMoveIndex >= replayData.moves.length) return;

                    // For the first move, set the start time
                    if (replayMoveIndex === 0 && !replayStartTime) {
                        replayStartTime = Date.now();
                        replayInitialTimestamp = replayData.moves[0].timestamp_ms;
                    }

                    const currentMove = replayData.moves[replayMoveIndex];
                    const elapsedReplayTime = Date.now() - replayStartTime;
                    const relativeMoveTime = currentMove.timestamp_ms - replayInitialTimestamp;

                    if (elapsedReplayTime >= relativeMoveTime / replaySpeed) {
                        // Set direction and update snake
                        replaySnake.setDir(currentMove.direction);
                        replaySnake.update();
                        
                        // Update food position
                        food.x = currentMove.food_x * boxSize;
                        food.y = currentMove.food_y * boxSize;
                        
                        // Handle snake growth/length changes
                        while (replaySnake.body.length < currentMove.snake_length) {
                            replaySnake.grow();
                        }
                        while (replaySnake.body.length > currentMove.snake_length) {
                            replaySnake.body.pop();
                        }
                        
                        // Update score and level displays during replay
                        if (currentMove.score !== undefined) {
                            score = currentMove.score;
                            gsap.to(scoreEl, {
                                duration: 0.3,
                                innerHTML: score,
                                snap: { innerHTML: 1 },
                                ease: "power2.out"
                            });
                        }
                        if (currentMove.level !== undefined) {
                            level = currentMove.level;
                            gsap.to(levelEl, {
                                duration: 0.3,
                                innerHTML: level,
                                snap: { innerHTML: 1 },
                                ease: "power2.out"
                            });
                        }
                        
                        // Restore power-ups state
                        if (currentMove.power_ups_data) {
                            try {
                                const powerUpsData = JSON.parse(currentMove.power_ups_data);
                                powerUps = powerUpsData.map(pu => ({
                                    pos: p.createVector(pu.x * boxSize, pu.y * boxSize),
                                    type: pu.type,
                                    show: function() {
                                        p.push();
                                        p.translate(this.pos.x + boxSize/2, this.pos.y + boxSize/2);
                                        
                                        switch(this.type) {
                                            case 'speed':
                                                p.fill(59, 130, 246);
                                                p.rotate(p.frameCount * 0.05);
                                                p.triangle(0, -8, -7, 6, 7, 6);
                                                break;
                                            case 'shield':
                                                p.fill(139, 92, 246);
                                                p.ellipse(0, 0, boxSize * 0.8);
                                                p.fill(255);
                                                p.textSize(12);
                                                p.textAlign(p.CENTER, p.CENTER);
                                                p.text('🛡', 0, 0);
                                                break;
                                            case 'slow':
                                                p.fill(236, 72, 153);
                                                p.rectMode(p.CENTER);
                                                p.rect(0, 0, boxSize * 0.7, boxSize * 0.7, 4);
                                                p.fill(255);
                                                p.textSize(12);
                                                p.textAlign(p.CENTER, p.CENTER);
                                                p.text('🐢', 0, 0);
                                                break;
                                            case 'duplicate':
                                                p.fill(245, 158, 11);
                                                p.rectMode(p.CENTER);
                                                p.push();
                                                p.translate(-4, 0);
                                                p.rect(0, 0, boxSize * 0.4, boxSize * 0.4, 2);
                                                p.pop();
                                                p.push();
                                                p.translate(4, 0);
                                                p.rect(0, 0, boxSize * 0.4, boxSize * 0.4, 2);
                                                p.pop();
                                                break;
                                        }
                                        p.pop();
                                    }
                                }));
                            } catch (e) {
                                console.warn('Failed to parse power-ups data:', e);
                            }
                        }
                        
                        // Restore obstacles state
                        if (currentMove.obstacles_data) {
                            try {
                                const obstaclesData = JSON.parse(currentMove.obstacles_data);
                                obstacles = obstaclesData.map(obs => p.createVector(obs.x * boxSize, obs.y * boxSize));
                            } catch (e) {
                                console.warn('Failed to parse obstacles data:', e);
                            }
                        }
                        
                        // Handle special events
                        if (currentMove.event_type) {
                            handleReplayEvent(currentMove.event_type, currentMove.event_data);
                        }
                        
                        replayMoveIndex++;
                        // Update progress/time UI
                        const lastIdx = replayData.moves.length - 1;
                        const pct = Math.round((replayMoveIndex - 1) / Math.max(1, lastIdx) * 100);
                        if (replaySeek) replaySeek.value = String(pct);
                        const curMs = currentMove.timestamp_ms - replayInitialTimestamp;
                        updateReplayTime(curMs, replayTotalDuration);
                        
                        // Check if replay is complete after processing the move
                        if (replayMoveIndex >= replayData.moves.length) {
                            replayPaused = true;
                            replayInfoEl.textContent = 'Replay completed!';
                        }
                    }
                } catch (error) {
                    console.error('Error during replay:', error);
                    replayPaused = true;
                    replayInfoEl.textContent = 'Replay error: ' + error.message;
                }
            }

            function handleReplayEvent(eventType, eventDataStr) {
                try {
                    const eventData = eventDataStr ? JSON.parse(eventDataStr) : null;
                    
                    switch(eventType) {
                        case 'food_eaten':
                            // Play food eating sound
                            if (eatSound) {
                                eatSound.currentTime = 0;
                                eatSound.play().catch(e => {});
                            }
                            // Show eating particle effect
                            if (eventData && eventData.position) {
                                showFoodEatenEffect(eventData.position.x * boxSize, eventData.position.y * boxSize);
                            }
                            break;
                        case 'power_up_collected':
                            // Show power-up collection effect
                            if (eventData) {
                                showPowerUpNotification(`${eventData.type.toUpperCase()} COLLECTED!`, '#10b981');
                            }
                            break;
                        case 'level_up':
                            // Show level up effect
                            showPowerUpNotification(`LEVEL ${eventData.new_level}!`, '#3b82f6');
                            break;
                    }
                } catch (e) {
                    console.warn('Failed to handle replay event:', e);
                }
            }

            function showRecordingIndicator() {
                if (recordingIndicatorEl) {
                    recordingIndicatorEl.classList.remove('hidden');
                }
            }

            function hideRecordingIndicator() {
                if (recordingIndicatorEl) {
                    recordingIndicatorEl.classList.add('hidden');
                }
            }

            function msToMMSS(ms) {
                const totalSec = Math.max(0, Math.floor(ms / 1000));
                const m = Math.floor(totalSec / 60).toString().padStart(2, '0');
                const s = (totalSec % 60).toString().padStart(2, '0');
                return `${m}:${s}`;
            }

            function updateReplayTime(currentMs, totalMs) {
                if (replayTime) {
                    replayTime.textContent = `${msToMMSS(currentMs)} / ${msToMMSS(totalMs)}`;
                }
            }

        function rebuildReplaySnakeToIndex(targetIndex) {
                if (!replayData || targetIndex < 0) return;
                
                replaySnake = new Snake();
                powerUps = [];
                obstacles = [];
                
                // Initialize from stored initial state if available
                if (replayData.initial_state) {
                    const initialState = replayData.initial_state;
                    const startX = initialState.initial_snake_x * initialState.box_size;
                    const startY = initialState.initial_snake_y * initialState.box_size;
                    
                    replaySnake.body = [p.createVector(startX, startY)];
                    
                    // Set initial direction
                    switch(initialState.initial_snake_direction) {
                        case 'right': replaySnake.xdir = 1; replaySnake.ydir = 0; break;
                        case 'left': replaySnake.xdir = -1; replaySnake.ydir = 0; break;
                        case 'up': replaySnake.xdir = 0; replaySnake.ydir = -1; break;
                        case 'down': replaySnake.xdir = 0; replaySnake.ydir = 1; break;
                    }
                    
                    // Set initial food position
                    food = p.createVector(
                        initialState.initial_food_x * initialState.box_size, 
                        initialState.initial_food_y * initialState.box_size
                    );
                } else {
                    // Fallback initialization
                    replaySnake.body = [p.createVector(
                        Math.floor(cols / 2) * boxSize,
                        Math.floor(rows / 2) * boxSize
                    )];
                    replaySnake.xdir = 1;
                    replaySnake.ydir = 0;
                    food = p.createVector(0, 0);
                }
                
                // Replay all moves up to targetIndex
                for (let i = 0; i <= targetIndex && i < replayData.moves.length; i++) {
                    const mv = replayData.moves[i];
                    replaySnake.setDir(mv.direction);
                    replaySnake.update();
                    
                    // Adjust snake length
                    while (replaySnake.body.length < mv.snake_length) {
                        replaySnake.grow();
                    }
                    while (replaySnake.body.length > mv.snake_length) {
                        replaySnake.body.pop();
                    }
                    
                    // Update food position
                    food.x = mv.food_x * boxSize;
                    food.y = mv.food_y * boxSize;
                    
                    // Update score and level
                    if (mv.score !== undefined) score = mv.score;
                    if (mv.level !== undefined) level = mv.level;
                    
                    // Restore power-ups and obstacles state for this move
                    if (mv.power_ups_data) {
                        try {
                            const powerUpsData = JSON.parse(mv.power_ups_data);
                            powerUps = powerUpsData.map(pu => ({
                                pos: p.createVector(pu.x * boxSize, pu.y * boxSize),
                                type: pu.type,
                                show: function() {
                                    p.push();
                                    p.translate(this.pos.x + boxSize/2, this.pos.y + boxSize/2);
                                    
                                    switch(this.type) {
                                        case 'speed':
                                            p.fill(59, 130, 246);
                                            p.rotate(p.frameCount * 0.05);
                                            p.triangle(0, -8, -7, 6, 7, 6);
                                            break;
                                        case 'shield':
                                            p.fill(139, 92, 246);
                                            p.ellipse(0, 0, boxSize * 0.8);
                                            break;
                                        case 'slow':
                                            p.fill(236, 72, 153);
                                            p.rectMode(p.CENTER);
                                            p.rect(0, 0, boxSize * 0.7, boxSize * 0.7, 4);
                                            break;
                                        case 'duplicate':
                                            p.fill(245, 158, 11);
                                            p.rectMode(p.CENTER);
                                            p.rect(0, 0, boxSize * 0.4, boxSize * 0.4, 2);
                                            break;
                                    }
                                    p.pop();
                                }
                            }));
                        } catch (e) {
                            console.warn('Failed to parse power-ups data during rebuild:', e);
                        }
                    }
                    
                    if (mv.obstacles_data) {
                        try {
                            const obstaclesData = JSON.parse(mv.obstacles_data);
                            obstacles = obstaclesData.map(obs => p.createVector(obs.x * boxSize, obs.y * boxSize));
                        } catch (e) {
                            console.warn('Failed to parse obstacles data during rebuild:', e);
                        }
                    }
                }
                
                // Update UI displays
                gsap.set(scoreEl, { innerHTML: score });
                gsap.set(levelEl, { innerHTML: level });
            }

            function seekReplay(percent) {
                if (!replayData || replayData.moves.length === 0) return;
                const pct = Math.max(0, Math.min(100, percent));
                const lastIdx = replayData.moves.length - 1;
                const targetIndex = Math.round((pct / 100) * lastIdx);
                // Build state up to targetIndex, then set next move to targetIndex + 1
                replayMoveIndex = Math.min(targetIndex + 1, lastIdx);
                rebuildReplaySnakeToIndex(replayMoveIndex);
                // Reset timing baselines so next move triggers from now
                replayInitialTimestamp = replayData.moves[replayMoveIndex]?.timestamp_ms ?? replayData.moves[0].timestamp_ms;
                replayStartTime = Date.now();
                replayPaused = false;
                const curIdx = Math.max(0, replayMoveIndex - 1);
                updateReplayTime(replayData.moves[curIdx].timestamp_ms - replayData.moves[0].timestamp_ms, replayTotalDuration);
            }

            function stepReplay(delta) {
                if (!replayData) return;
                replayPaused = true;
                const lastIdx = replayData.moves.length - 1;
                let next = replayMoveIndex + delta;
                if (next < 0) next = 0;
                if (next > lastIdx) next = lastIdx;
                replayMoveIndex = next;
                const pct = Math.round((replayMoveIndex) / Math.max(1, lastIdx) * 100);
                if (replaySeek) replaySeek.value = String(pct);
                rebuildReplaySnakeToIndex(replayMoveIndex);
                // Align timing to current index so resuming is consistent
                replayInitialTimestamp = replayData.moves[replayMoveIndex]?.timestamp_ms ?? replayData.moves[0].timestamp_ms;
                replayStartTime = Date.now();
                updateReplayTime(replayData.moves[replayMoveIndex].timestamp_ms - replayData.moves[0].timestamp_ms, replayTotalDuration);
            }

            function restartReplay() {
                if (!replayData) return;
                replayPaused = false;
                replaySpeed = 1;
                replaySpeedButton.textContent = `${translations[currentLang].speedButton}: 1x`;
                replayMoveIndex = 0;
                replayInitialTimestamp = replayData.moves[0].timestamp_ms;
                replayStartTime = Date.now();
                if (replaySeek) replaySeek.value = '0';
                updateReplayTime(0, replayTotalDuration);
                replaySnake = new Snake();
                replaySnake.body = [p.createVector(
                    Math.floor(cols / 2) * boxSize,
                    Math.floor(rows / 2) * boxSize
                )];
                replaySnake.xdir = 1;
                replaySnake.ydir = 0;
                const firstMove = replayData.moves[0];
                while (replaySnake.body.length < firstMove.snake_length) replaySnake.grow();
                food = p.createVector(firstMove.food_x * boxSize, firstMove.food_y * boxSize);
                food.scale = 1;
                replayInfoEl.textContent = `Replaying ${replayData.game.player_name}'s game (Score: ${replayData.game.score})`;
            }

            function toggleReplayPause() {
                replayPaused = !replayPaused;
                const t = translations[currentLang];
                replayPauseButton.textContent = replayPaused ? t.resumeButton : t.pauseButton;
            }

            function cycleReplaySpeed() {
                const speeds = [0.5, 1, 2, 4];
                const currentIndex = speeds.indexOf(replaySpeed);
                replaySpeed = speeds[(currentIndex + 1) % speeds.length];
                replaySpeedButton.textContent = `${translations[currentLang].speedButton}: ${replaySpeed}x`;
                // Update frame rate but ensure it's at least 1
                // Speed affects logical catch-up, not draw rate
            }

            function exitReplay() {
                isReplayMode = false;
                // Show recording indicator again when exiting replay
                showRecordingIndicator();
                replayData = null;
                replaySnake = null;
                replayMoveIndex = 0;
                replayPaused = false;
                replaySpeed = 1;
                replayStartTime = null;
                replayInitialTimestamp = null;
                replayTotalDuration = 0;
                if (replayKeydownHandler) {
                    document.removeEventListener('keydown', replayKeydownHandler);
                    replayKeydownHandler = null;
                }
                
                // Use GSAP timeline for exit animation
                const exitTl = createPanelExitTimeline(replayOverlay);
                exitTl.eventCallback("onComplete", () => {
                    replayOverlay.style.display = 'none';
                    
                    // Reset game state
                    isGameOver = false;
                    score = 0;
                    level = 1;
                    
                    // Re-initialize the game
                    snake = new Snake();
                    placeFood();
                    powerUps = [];
                    obstacles = [];
                    
                    // Reset UI
                    gsap.to(scoreEl, {
                        duration: 0.5,
                        innerHTML: score,
                        snap: { innerHTML: 1 },
                        ease: "power2.out"
                    });
                    gsap.to(levelEl, {
                        duration: 0.5,
                        innerHTML: level,
                        snap: { innerHTML: 1 },
                        ease: "power2.out"
                    });
                    updateProgress();
                    
                    // Restart the game loop
                    p.frameRate(10);
                    p.loop();

                    // Return to name entry
                    nameEntryOverlay.style.display = 'flex';
                    setupNameEntry();
                });
                exitTl.play();
            }

            function setupTutorial() {
                setLeaderboardVisibility(false);
                
                // Use GSAP timeline for entrance animation
                tutorialOverlay.style.display = 'flex';
                const tl = createPanelEntranceTimeline(tutorialOverlay);
                tl.play();
            }

            function startGame() {
                gsap.killTweensOf("#tutorial-overlay");
                
                // Play intro splash sound on game start
                if (introSplashSound) {
                    introSplashSound.currentTime = 0;
                    introSplashSound.play().catch(e => console.log("Sound play prevented by browser:", e));
                }
                
                // Use GSAP timeline for exit animation
                const exitTl = createPanelExitTimeline(tutorialOverlay);
                exitTl.eventCallback("onComplete", () => {
                    tutorialOverlay.style.display = 'none';
                    initializeNewGame();
                });
                exitTl.play();
            }

            function initializeNewGame() {
                resetGame();
                setLeaderboardVisibility(true);
                gameStartTime = Date.now();
                moveSequence = 0;
                gameMoves = [];
                powerUps = [];
                obstacles = [];
                // Show recording indicator
                showRecordingIndicator();
                // Make sure we have a player name, generate one if needed
                if (!playerName) {
                    playerName = generateRandomName();
                }
                saveGameData(); // Save initial game state and get game ID
            }

            function placeFood() {
                let newFoodPos = p.createVector(p.floor(p.random(cols)), p.floor(p.random(rows)));
                newFoodPos.mult(boxSize);
                while (snake.isOnSnake(newFoodPos) || isPositionOccupied(newFoodPos)) {
                    newFoodPos = p.createVector(p.floor(p.random(cols)), p.floor(p.random(rows)));
                    newFoodPos.mult(boxSize);
                }
                food = newFoodPos;
                food.scale = 0;
                gsap.to(food, {
                    scale: 1,
                    duration: 0.5,
                    ease: "elastic.out(1, 0.5)"
                });
            }
            
            function addPowerUp() {
                // Only add if less than 2 power-ups on screen
                if (powerUps.length >= 2) return;
                
                let newPowerUpPos = p.createVector(p.floor(p.random(cols)), p.floor(p.random(rows)));
                newPowerUpPos.mult(boxSize);
                
                // Make sure position is not occupied
                while (snake.isOnSnake(newPowerUpPos) || 
                       (food && newPowerUpPos.x === food.x && newPowerUpPos.y === food.y) ||
                       isPositionOccupied(newPowerUpPos)) {
                    newPowerUpPos = p.createVector(p.floor(p.random(cols)), p.floor(p.random(rows)));
                    newPowerUpPos.mult(boxSize);
                }
                
                // Random power-up type
                const types = ['speed', 'shield', 'slow', 'duplicate'];
                const type = types[Math.floor(Math.random() * types.length)];
                
                powerUps.push({
                    pos: newPowerUpPos,
                    type: type,
                    show: function() {
                        p.push();
                        p.translate(this.pos.x + boxSize/2, this.pos.y + boxSize/2);
                        
                        // Draw different shapes based on type
                        switch(this.type) {
                            case 'speed':
                                p.fill(59, 130, 246); // Blue
                                p.rotate(p.frameCount * 0.05);
                                p.triangle(0, -8, -7, 6, 7, 6);
                                break;
                            case 'shield':
                                p.fill(139, 92, 246); // Purple
                                p.ellipse(0, 0, boxSize * 0.8);
                                p.fill(255);
                                p.textSize(12);
                                p.textAlign(p.CENTER, p.CENTER);
                                p.text('🛡', 0, 0);
                                break;
                            case 'slow':
                                p.fill(236, 72, 153); // Pink
                                p.rectMode(p.CENTER);
                                p.rect(0, 0, boxSize * 0.7, boxSize * 0.7, 4);
                                p.fill(255);
                                p.textSize(12);
                                p.textAlign(p.CENTER, p.CENTER);
                                p.text('🐢', 0, 0);
                                break;
                            case 'duplicate':
                                p.fill(245, 158, 11); // Amber
                                p.rectMode(p.CENTER);
                                p.push();
                                p.translate(-4, 0);
                                p.rect(0, 0, boxSize * 0.4, boxSize * 0.4, 2);
                                p.pop();
                                p.push();
                                p.translate(4, 0);
                                p.rect(0, 0, boxSize * 0.4, boxSize * 0.4, 2);
                                p.pop();
                                break;
                        }
                        
                        p.pop();
                    }
                });
            }
            
            function showPowerUpNotification(text, color) {
                // Create a temporary notification element
                const notification = document.createElement('div');
                notification.textContent = text;
                notification.style.position = 'absolute';
                notification.style.top = '50%';
                notification.style.left = '50%';
                notification.style.transform = 'translate(-50%, -50%)';
                notification.style.color = color;
                notification.style.fontSize = '24px';
                notification.style.fontWeight = 'bold';
                notification.style.textShadow = '0 0 10px rgba(255, 255, 255, 0.8)';
                notification.style.zIndex = '100';
                notification.style.pointerEvents = 'none';
                notification.style.opacity = '0';
                
                // Add to canvas container
                const canvasContainer = document.getElementById('canvas-container');
                canvasContainer.appendChild(notification);
                
                // Animate the notification
                gsap.to(notification, {
                    opacity: 1,
                    duration: 0.3,
                    y: -50,
                    onComplete: () => {
                        gsap.to(notification, {
                            opacity: 0,
                            duration: 0.5,
                            delay: 1.2,
                            onComplete: () => {
                                canvasContainer.removeChild(notification);
                            }
                        });
                    }
                });
            }
            
            function createPanelEntranceTimeline(element, delay = 0) {
                const tl = gsap.timeline({ delay: delay });
                tl.fromTo(element, 
                    { opacity: 0, y: 20 },
                    { opacity: 1, y: 0, duration: 0.6, ease: "power2.out" }
                );
                return tl;
            }
            
            function createPanelExitTimeline(element, delay = 0) {
                const tl = gsap.timeline({ delay: delay });
                tl.to(element, 
                    { opacity: 0, y: -20, duration: 0.4, ease: "power2.in" }
                );
                return tl;
            }
            
            function activatePowerUp(type) {
                // Play sound effect
                if (foodSound) {
                    foodSound.currentTime = 0;
                    foodSound.play().catch(e => {});
                }
                
                // Add visual effect for powerup collection
                createPowerUpParticles();
                
                switch(type) {
                    case 'speed':
                        activePowerUps.speed.active = true;
                        activePowerUps.speed.timer = 300; // 5 seconds at 60fps
                        p.frameRate(10 + (level * 2) + 10); // Increase speed
                        // Visual feedback for speed powerup
                        showPowerUpNotification("SPEED BOOST!", "rgb(59, 130, 246)");
                        break;
                    case 'shield':
                        activePowerUps.shield.active = true;
                        activePowerUps.shield.timer = 600; // 10 seconds
                        // Visual feedback for shield powerup
                        showPowerUpNotification("SHIELD ACTIVATED!", "rgb(139, 92, 246)");
                        break;
                    case 'slow':
                        activePowerUps.slow.active = true;
                        activePowerUps.slow.timer = 300; // 5 seconds
                        p.frameRate(Math.max(5, 10 + (level * 2) - 5)); // Decrease speed
                        // Visual feedback for slow powerup
                        showPowerUpNotification("SLOW MOTION!", "rgb(236, 72, 153)");
                        break;
                    case 'duplicate':
                        activePowerUps.duplicate.active = true;
                        activePowerUps.duplicate.timer = 600; // 10 seconds
                        // Create duplicate snake
                        createDuplicateSnake();
                        // Visual feedback for duplicate powerup
                        showPowerUpNotification("DUPLICATE SNAKE!", "rgb(245, 158, 11)");
                        break;
                }
            }
            
            function updatePowerUps() {
                // Get powerup indicator elements
                const speedIndicator = document.querySelector('.powerup-speed');
                const shieldIndicator = document.querySelector('.powerup-shield');
                const slowIndicator = document.querySelector('.powerup-slow');
                const duplicateIndicator = document.querySelector('.powerup-duplicate');
                
                // Update timers and deactivate power-ups
                if (activePowerUps.speed.active) {
                    activePowerUps.speed.timer--;
                    // Visual feedback for active speed powerup
                    if (speedIndicator) {
                        speedIndicator.style.boxShadow = `0 0 10px rgba(59, 130, 246, ${0.5 + 0.5 * Math.sin(Date.now() / 100)})`;
                        speedIndicator.style.transform = `scale(${1 + 0.1 * Math.sin(Date.now() / 100)})`;
                    }
                    if (activePowerUps.speed.timer <= 0) {
                        activePowerUps.speed.active = false;
                        p.frameRate(10 + level * 2); // Reset to normal speed
                        if (speedIndicator) {
                            speedIndicator.style.boxShadow = 'none';
                            speedIndicator.style.transform = 'scale(1)';
                        }
                        showPowerUpNotification("SPEED BOOST ENDED", "rgb(59, 130, 246)");
                    }
                } else if (speedIndicator) {
                    speedIndicator.style.boxShadow = 'none';
                    speedIndicator.style.transform = 'scale(1)';
                }
                
                if (activePowerUps.shield.active) {
                    activePowerUps.shield.timer--;
                    // Visual feedback for active shield powerup
                    if (shieldIndicator) {
                        shieldIndicator.style.boxShadow = `0 0 10px rgba(139, 92, 246, ${0.5 + 0.5 * Math.sin(Date.now() / 100)})`;
                        shieldIndicator.style.transform = `scale(${1 + 0.1 * Math.sin(Date.now() / 100)})`;
                    }
                    if (activePowerUps.shield.timer <= 0) {
                        activePowerUps.shield.active = false;
                        if (shieldIndicator) {
                            shieldIndicator.style.boxShadow = 'none';
                            shieldIndicator.style.transform = 'scale(1)';
                        }
                        showPowerUpNotification("SHIELD DEACTIVATED", "rgb(139, 92, 246)");
                    }
                } else if (shieldIndicator) {
                    shieldIndicator.style.boxShadow = 'none';
                    shieldIndicator.style.transform = 'scale(1)';
                }
                
                if (activePowerUps.slow.active) {
                    activePowerUps.slow.timer--;
                    // Visual feedback for active slow powerup
                    if (slowIndicator) {
                        slowIndicator.style.boxShadow = `0 0 10px rgba(236, 72, 153, ${0.5 + 0.5 * Math.sin(Date.now() / 100)})`;
                        slowIndicator.style.transform = `scale(${1 + 0.1 * Math.sin(Date.now() / 100)})`;
                    }
                    if (activePowerUps.slow.timer <= 0) {
                        activePowerUps.slow.active = false;
                        p.frameRate(10 + level * 2); // Reset to normal speed
                        if (slowIndicator) {
                            slowIndicator.style.boxShadow = 'none';
                            slowIndicator.style.transform = 'scale(1)';
                        }
                        showPowerUpNotification("SLOW MOTION ENDED", "rgb(236, 72, 153)");
                    }
                } else if (slowIndicator) {
                    slowIndicator.style.boxShadow = 'none';
                    slowIndicator.style.transform = 'scale(1)';
                }
                
                if (activePowerUps.duplicate.active) {
                    activePowerUps.duplicate.timer--;
                    // Visual feedback for active duplicate powerup
                    if (duplicateIndicator) {
                        duplicateIndicator.style.boxShadow = `0 0 10px rgba(245, 158, 11, ${0.5 + 0.5 * Math.sin(Date.now() / 100)})`;
                        duplicateIndicator.style.transform = `scale(${1 + 0.1 * Math.sin(Date.now() / 100)})`;
                    }
                    if (activePowerUps.duplicate.timer <= 0) {
                        activePowerUps.duplicate.active = false;
                        // Remove duplicate snake
                        removeDuplicateSnake();
                        if (duplicateIndicator) {
                            duplicateIndicator.style.boxShadow = 'none';
                            duplicateIndicator.style.transform = 'scale(1)';
                        }
                        showPowerUpNotification("DUPLICATE SNAKE ENDED", "rgb(245, 158, 11)");
                    }
                } else if (duplicateIndicator) {
                    duplicateIndicator.style.boxShadow = 'none';
                    duplicateIndicator.style.transform = 'scale(1)';
                }
            }
            
            function addObstacle() {
                // Only add if less than level/2 obstacles
                if (obstacles.length >= Math.floor(level/2)) return;
                
                let newObstaclePos = p.createVector(p.floor(p.random(cols)), p.floor(p.random(rows)));
                newObstaclePos.mult(boxSize);
                
                // Make sure position is not occupied
                while (snake.isOnSnake(newObstaclePos) || 
                       (food && newObstaclePos.x === food.x && newObstaclePos.y === food.y) ||
                       isPositionOccupied(newObstaclePos)) {
                    newObstaclePos = p.createVector(p.floor(p.random(cols)), p.floor(p.random(rows)));
                    newObstaclePos.mult(boxSize);
                }
                
                obstacles.push(newObstaclePos);
            }
            
            function isPositionOccupied(pos) {
                // Check if position is occupied by obstacles
                for (let obs of obstacles) {
                    if (obs.x === pos.x && obs.y === pos.y) {
                        return true;
                    }
                }
                
                // Check if position is occupied by power-ups
                for (let powerUp of powerUps) {
                    if (powerUp.pos.x === pos.x && powerUp.pos.y === pos.y) {
                        return true;
                    }
                }
                
                return false;
            }

            function gameOver() {
                if (isReplayMode || isGameOver) return;
                isGameOver = true;
                // Hide recording indicator when game ends
                hideRecordingIndicator();
                setLeaderboardVisibility(false);
                // Display player name
                playerNameDisplay.textContent = playerName;
                // Animate final score with GSAP
                gsap.to(finalScoreEl, {
                    duration: 1,
                    innerHTML: score,
                    snap: { innerHTML: 1 },
                    ease: "power2.out"
                });
                
                // Use GSAP timeline for entrance animation
                gameOverEl.style.display = 'flex';
                // Kill any existing animations before starting new ones
                gsap.killTweensOf(gameOverEl);
                const tl = createPanelEntranceTimeline(gameOverEl);
                tl.play();
                
                // Play intro splash sound again
                if (introSplashSound) {
                    introSplashSound.currentTime = 0;
                    introSplashSound.play().catch(e => {});
                }
                // Final game save with updated score
                if (gameId) {
                    saveGameData();
                }
                // Show leaderboard directly
                fetchLeaderboard();
                p.noLoop();
            }

            function resetGame() {
                if (isReplayMode) {
                    exitReplay();
                    return;
                }

                // Kill any ongoing animations
                gsap.killTweensOf(gameOverEl);
                gsap.killTweensOf(nameEntryOverlay);
                gsap.killTweensOf(scoreEl);

                isGameOver = false;
                score = 0;
                level = 1;
                direction = 'right';
                playerRank = Infinity;
                particles = [];
                moveSequence = 0;
                gameMoves = [];
                gameId = null;
                powerUps = [];
                obstacles = [];

                // Reset power-ups
                activePowerUps = {
                    speed: { active: false, timer: 0 },
                    shield: { active: false, timer: 0 },
                    slow: { active: false, timer: 0 },
                    duplicate: { active: false, timer: 0 }
                };

                // Reset score display
                gsap.to(scoreEl, {
                    duration: 0.5,
                    innerHTML: score,
                    snap: { innerHTML: 1 },
                    ease: "power2.out"
                });
                gsap.to(levelEl, {
                    duration: 0.5,
                    innerHTML: level,
                    snap: { innerHTML: 1 },
                    ease: "power2.out"
                });
                updateProgress();
                gameOverEl.style.display = 'none';

                snake = new Snake();
                placeFood();
                p.loop();
                p.frameRate(10);
            }
            
            function returnToMenu() {
                console.log('Returning to menu');
                
                // Make sure input field is enabled
                playerNameInput.disabled = false;
                playerNameInput.readOnly = false;
                
                // Use GSAP timeline for exit animation
                const exitTl = createPanelExitTimeline(gameOverEl);
                exitTl.eventCallback("onComplete", () => {
                    gameOverEl.style.display = 'none';
                    nameEntryOverlay.style.display = 'flex';
                    setupNameEntry();
                });
                exitTl.play();
                
                // Re-enable the post score button
                postScoreButton.disabled = false;
            }
            
            function postScore() {
                // Prevent multiple clicks
                postScoreButton.disabled = true;
                
                // Save game data if not already saved
                if (gameId) {
                    saveGameData();
                }
                
                // Show confirmation message
                const t = translations[currentLang];
                alert(`Score posted successfully, ${playerName}!`);
                
                // Remove the game over screen immediately
                const exitTl = createPanelExitTimeline(gameOverEl);
                exitTl.eventCallback("onComplete", () => {
                    gameOverEl.style.display = 'none';
                    nameEntryOverlay.style.display = 'flex';
                    setupNameEntry();
                });
                exitTl.play();
            }
            
            function updateProgress() {
                // Update progress bar (0-5 food per level)
                const progress = (score % 5) * 20;
                progressFill.style.width = `${progress}%`;
            }

            async function fetchLeaderboard() {
                try {
                    const response = await fetch('?action=get_leaderboard');
                    if (!response.ok) throw new Error('Network response was not ok');
                    leaderboardData = await response.json();
                    updateLeaderboardUI();
                } catch (error) {
                    console.error('Failed to fetch leaderboard:', error);
                    leaderboardListEl.innerHTML = `<li class='text-red-500'>Error loading</li>`;
                }
            }

            function updateLeaderboardUI() {
                leaderboardListEl.innerHTML = '';
                if (leaderboardData.length === 0) {
                    leaderboardListEl.innerHTML = `<li class='opacity-50'>No scores yet!</li>`;
                    return;
                }

                leaderboardData.forEach((entry, index) => {
                    const li = document.createElement('li');
                    li.className = 'flex justify-between items-center p-2 hover:bg-gray-700 rounded cursor-pointer transition-colors';

                    const scoreInfo = document.createElement('div');
                    scoreInfo.innerHTML = `<span class="font-bold text-yellow-300">${entry.score}</span> - ${entry.player.substring(0, 12)}`;

                    const replayBtn = document.createElement('button');
                    replayBtn.className = 'text-xs bg-blue-600 hover:bg-blue-500 px-2 py-1 rounded text-white';
                    replayBtn.textContent = translations[currentLang].watchReplayButton.substring(0, 6);
                    replayBtn.onclick = (e) => {
                        e.stopPropagation();
                        startReplay(entry.game_id);
                    };

                    li.appendChild(scoreInfo);
                    li.appendChild(replayBtn);
                    leaderboardListEl.appendChild(li);
                });
            }

            function checkRankAndConfetti() {
                let newRank = Infinity;
                for (let i = 0; i < leaderboardData.length; i++) {
                    if (score > leaderboardData[i].score) {
                        newRank = i + 1;
                        break;
                    }
                }
                if (newRank === Infinity && leaderboardData.length < 5) {
                    newRank = leaderboardData.length + 1;
                }
                if (newRank < playerRank) {
                    playerRank = newRank;
                    confetti({
                        particleCount: 150,
                        spread: 90,
                        origin: {
                            y: 0.6
                        },
                        zIndex: 9999
                    });
                }
            }

            // --- NEW: Particle System ---
            function createParticles(x, y) {
                for (let i = 0; i < 15; i++) {
                    particles.push(new Particle(x + boxSize / 2, y + boxSize / 2));
                }
            }
            
            // Create particles when collecting a power-up
            function createPowerUpParticles() {
                for (let i = 0; i < 30; i++) {
                    particles.push(new PowerUpParticle());
                }
            }
            
            function createDuplicateSnake() {
                // Create a duplicate of the current snake
                duplicateSnake = new Snake();
                // Copy the body of the original snake
                duplicateSnake.body = [];
                for (let i = 0; i < snake.body.length; i++) {
                    duplicateSnake.body.push(snake.body[i].copy());
                }
                // Copy the direction
                duplicateSnake.xdir = snake.xdir;
                duplicateSnake.ydir = snake.ydir;
            }
            
            function removeDuplicateSnake() {
                duplicateSnake = null;
            }
            
            function createPanelEntranceTimeline(element, delay = 0) {
                const tl = gsap.timeline({ delay: delay });
                tl.fromTo(element, 
                    { opacity: 0, y: 20 },
                    { opacity: 1, y: 0, duration: 0.6, ease: "power2.out" }
                );
                return tl;
            }
            
            function createPanelExitTimeline(element, delay = 0) {
                const tl = gsap.timeline({ delay: delay });
                tl.to(element, 
                    { opacity: 0, y: -20, duration: 0.4, ease: "power2.in" }
                );
                return tl;
            }
            
            function createDuplicateSnake() {
                // Create a duplicate of the current snake
                duplicateSnake = new Snake();
                // Copy the body of the original snake
                duplicateSnake.body = [];
                for (let i = 0; i < snake.body.length; i++) {
                    duplicateSnake.body.push(snake.body[i].copy());
                }
                // Copy the direction
                duplicateSnake.xdir = snake.xdir;
                duplicateSnake.ydir = snake.ydir;
            }
            
            function removeDuplicateSnake() {
                duplicateSnake = null;
            }
            class Particle {
                constructor(x, y) {
                    this.pos = p.createVector(x, y);
                    this.vel = p5.Vector.random2D().mult(p.random(1, 4));
                    this.lifespan = 255;
                    this.size = p.random(2, 5);
                }
                update() {
                    this.pos.add(this.vel);
                    this.lifespan -= 5;
                }
                show() {
                    p.noStroke();
                    p.fill(255, 100, 100, this.lifespan); // Fading red color
                    p.ellipse(this.pos.x, this.pos.y, this.size);
                }
                isFinished() {
                    return this.lifespan < 0;
                }
            }
            
            class PowerUpParticle {
                constructor() {
                    // Create particle at center of screen
                    this.pos = p.createVector(p.width / 2, p.height / 2);
                    // Random direction
                    this.vel = p5.Vector.random2D().mult(p.random(2, 8));
                    this.lifespan = 255;
                    this.size = p.random(3, 8);
                    // Random bright color for powerup effect
                    this.color = p.color(p.random(100, 255), p.random(100, 255), p.random(100, 255));
                }
                update() {
                    this.pos.add(this.vel);
                    this.lifespan -= 7;
                    // Add gravity effect
                    this.vel.y += 0.1;
                }
                show() {
                    p.noStroke();
                    p.fill(this.color.levels[0], this.color.levels[1], this.color.levels[2], this.lifespan);
                    p.ellipse(this.pos.x, this.pos.y, this.size);
                }
                isFinished() {
                    return this.lifespan < 0;
                }
            }

            // --- Snake Class (Enhanced) ---
            class Snake {
                constructor() {
                    this.body = [];
                    this.body[0] = p.createVector(p.floor(cols / 2), p.floor(rows / 2));
                    this.body[0].mult(boxSize);
                    this.xdir = 1;
                    this.ydir = 0;
                }
                setDir(dir) {
                    if (dir === 'up' && this.ydir !== 1) {
                        this.xdir = 0;
                        this.ydir = -1;
                    } else if (dir === 'down' && this.ydir !== -1) {
                        this.xdir = 0;
                        this.ydir = 1;
                    } else if (dir === 'left' && this.xdir !== 1) {
                        this.xdir = -1;
                        this.ydir = 0;
                    } else if (dir === 'right' && this.xdir !== -1) {
                        this.xdir = 1;
                        this.ydir = 0;
                    }
                }
                update() {
                    let head = this.body[this.body.length - 1].copy();
                    this.body.shift();
                    head.x += this.xdir * boxSize;
                    head.y += this.ydir * boxSize;
                    this.body.push(head);
                }
                grow() {
                    let head = this.body[this.body.length - 1].copy();
                    this.body.push(head);
                }
                eat(pos) {
                    if (!pos) return false;
                    let head = this.body[this.body.length - 1];
                    if (head.x === pos.x && head.y === pos.y) {
                        this.grow();
                        return true;
                    }
                    return false;
                }
                isOnSnake(pos) {
                    for (let part of this.body) {
                        if (part.x === pos.x && part.y === pos.y) {
                            return true;
                        }
                    }
                    return false;
                }
                checkCollision() {
                    let head = this.body[this.body.length - 1];
                    
                    // Wall collision
                    if (head.x >= p.width || head.x < 0 || head.y >= p.height || head.y < 0) {
                        if (!activePowerUps.shield.active) {
                            gameOver();
                            return;
                        }
                    }
                    
                    // Self collision
                    for (let i = 0; i < this.body.length - 1; i++) {
                        let part = this.body[i];
                        if (part.x === head.x && part.y === head.y) {
                            if (!activePowerUps.shield.active) {
                                gameOver();
                                return;
                            }
                        }
                    }
                    
                    // Obstacle collision
                    for (let obs of obstacles) {
                        if (head.x === obs.x && head.y === obs.y) {
                            if (!activePowerUps.shield.active) {
                                gameOver();
                                return;
                            }
                        }
                    }
                }
                
                // Check collision but don't trigger game over (for duplicate snake)
                checkCollisionNoGameOver() {
                    let head = this.body[this.body.length - 1];
                    
                    // Wall collision - just stop the duplicate snake
                    if (head.x >= p.width || head.x < 0 || head.y >= p.height || head.y < 0) {
                        return;
                    }
                    
                    // Self collision - just stop the duplicate snake
                    for (let i = 0; i < this.body.length - 1; i++) {
                        let part = this.body[i];
                        if (part.x === head.x && part.y === head.y) {
                            return;
                        }
                    }
                    
                    // Obstacle collision - just stop the duplicate snake
                    for (let obs of obstacles) {
                        if (head.x === obs.x && head.y === obs.y) {
                            return;
                        }
                    }
                }
                
                show(isDuplicate = false) {
                    for (let i = 0; i < this.body.length; i++) {
                        // Head color
                        if (i === this.body.length - 1) {
                            if (activePowerUps.shield.active && !isDuplicate) {
                                p.fill('rgb(139, 92, 246)'); // Purple when shielded
                            } else if (isDuplicate) {
                                p.fill('rgb(245, 158, 11)'); // Amber for duplicate snake
                            } else {
                                p.fill('rgb(45, 212, 191)'); // Teal for head
                            }
                        } 
                        // Body color with gradient
                        else {
                            if (isDuplicate) {
                                const colorValue = 140 - (i % 5) * 10;
                                p.fill(`rgb(${colorValue}, 140, 80)`); // Greenish gradient for duplicate
                            } else {
                                const colorValue = 165 - (i % 5) * 10;
                                p.fill(`rgb(16, ${colorValue}, 129)`); // Blue gradient
                            }
                        }
                        
                        p.noStroke();
                        p.rect(this.body[i].x, this.body[i].y, boxSize, boxSize, 4);
                        
                        // Add glow effect when powerups are active
                        if (!isDuplicate && (activePowerUps.speed.active || activePowerUps.shield.active || activePowerUps.slow.active)) {
                            p.fill(255, 255, 255, 30);
                            p.rect(this.body[i].x, this.body[i].y, boxSize + 4, boxSize + 4, 6);
                        }
                        
                        // Add eyes to the head
                        if (i === this.body.length - 1) {
                            p.fill(0);
                            const eyeSize = boxSize / 5;
                            
                            // Position eyes based on direction
                            if (this.xdir === 1) { // Right
                                p.ellipse(this.body[i].x + boxSize * 0.7, this.body[i].y + boxSize * 0.3, eyeSize);
                                p.ellipse(this.body[i].x + boxSize * 0.7, this.body[i].y + boxSize * 0.7, eyeSize);
                            } else if (this.xdir === -1) { // Left
                                p.ellipse(this.body[i].x + boxSize * 0.3, this.body[i].y + boxSize * 0.3, eyeSize);
                                p.ellipse(this.body[i].x + boxSize * 0.3, this.body[i].y + boxSize * 0.7, eyeSize);
                            } else if (this.ydir === 1) { // Down
                                p.ellipse(this.body[i].x + boxSize * 0.3, this.body[i].y + boxSize * 0.7, eyeSize);
                                p.ellipse(this.body[i].x + boxSize * 0.7, this.body[i].y + boxSize * 0.7, eyeSize);
                            } else { // Up
                                p.ellipse(this.body[i].x + boxSize * 0.3, this.body[i].y + boxSize * 0.3, eyeSize);
                                p.ellipse(this.body[i].x + boxSize * 0.7, this.body[i].y + boxSize * 0.3, eyeSize);
                            }
                        }
                    }
                }
            }

        };

        new p5(sketch);
    </script>
</body>

</html>