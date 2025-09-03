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
            food_x INTEGER NOT NULL,
            food_y INTEGER NOT NULL,
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
        !isset($data->food_x) || !isset($data->food_y)
    ) {
        http_response_code(400);
        echo json_encode(['message' => 'Missing required move data']);
        return;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO game_moves (game_id, move_sequence, direction, timestamp_ms, snake_length, food_x, food_y)
                              VALUES (:game_id, :move_sequence, :direction, :timestamp_ms, :snake_length, :food_x, :food_y)");
        $stmt->bindParam(':game_id', $data->game_id);
        $stmt->bindParam(':move_sequence', $data->move_sequence);
        $stmt->bindParam(':direction', $data->direction);
        $stmt->bindParam(':timestamp_ms', $data->timestamp_ms);
        $stmt->bindParam(':snake_length', $data->snake_length);
        $stmt->bindParam(':food_x', $data->food_x);
        $stmt->bindParam(':food_y', $data->food_y);
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

        echo json_encode([
            'game' => $game,
            'moves' => $moves
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
    <title>Snake Game</title>
    <!-- External Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/p5.js/1.9.0/p5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&family=Press+Start+2P&family=Tajawal:wght@400;700&display=swap"
        rel="stylesheet">
    <style>
        body {
        background-image: url('snake-game-bg.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
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
    }

    /* Glass effect overlay for better readability */
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.2);
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
            border-radius: 0.5rem;
        }

        input[type="text"]:focus {
            outline: none;
        }

        .font-game {
            font-family: 'Press Start 2P', cursive;
        }

        /* Glass effect for main game UI */
        .main-game-ui {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .main-game-ui h1 {
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
            margin-bottom: 0.5rem;
        }

        .main-game-ui .score-level > div {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
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
            border: 2px solid #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
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
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2),
                inset 0 -1px 0 rgba(0, 0, 0, 0.1);
        }

        canvas {
            border-radius: 15px;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.4),
                0 4px 16px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            touch-action: none;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            background: rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            #canvas-container {
                width: 95vw;
                height: 60vh;
                max-height: 60vh;
                padding: 15px;
                border-radius: 15px;
            }
            
            canvas {
                border-radius: 12px;
            }
            
            .main-game-ui {
                margin-bottom: 1rem;
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
            #name-entry-overlay h2,
            #tutorial-overlay h2,
            #game-over h2,
            #replay-overlay h2 {
                font-size: 2rem;
                margin-bottom: 1rem;
            }

            #name-entry-overlay input {
                width: 90%;
                font-size: 1rem;
                padding: 0.75rem;
            }

            #name-entry-overlay button,
            #tutorial-overlay button,
            #game-over button,
            #replay-overlay button {
                font-size: 0.9rem;
                padding: 0.75rem 1.5rem;
            }

            #tutorial-overlay p {
                font-size: 1rem;
                margin-bottom: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            #canvas-container {
                width: 98vw;
                height: 55vh;
                max-height: 55vh;
                padding: 12px;
                border-radius: 12px;
            }
            
            canvas {
                border-radius: 10px;
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
            #name-entry-overlay h2,
            #tutorial-overlay h2,
            #game-over h2,
            #replay-overlay h2 {
                font-size: 1.5rem;
                margin-bottom: 0.75rem;
            }

            #name-entry-overlay input {
                width: 95%;
                font-size: 0.9rem;
                padding: 0.6rem;
            }

            #name-entry-overlay button,
            #tutorial-overlay button,
            #game-over button,
            #replay-overlay button {
                font-size: 0.8rem;
                padding: 0.6rem 1.2rem;
            }

            #tutorial-overlay p {
                font-size: 0.9rem;
                margin-bottom: 1rem;
            }

            #name-entry-overlay .flex {
                flex-direction: column;
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
            background: rgba(0, 0, 0, 0.5);
            padding: 1rem;
            border-radius: 15px;
            width: 280px;
            max-width: 85vw;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease-in-out;
            transform: translateX(0);
        }

        #leaderboard-container.expanded {
            left: 4px;
        }

        #leaderboard-toggle {
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 40;
            background: rgba(0, 0, 0, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem;
            border-radius: 12px;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        #leaderboard-toggle:hover {
            transform: scale(1.1);
        }

        /* Retractable leaderboard on mobile */
        @media (max-width: 768px) {
            #leaderboard-container {
                position: fixed;
                top: 10px;
                left: -260px;
                width: 280px;
                max-width: 80vw;
                background: rgba(0,0,0,0.85);
                border-radius: 0 0.75rem 0.75rem 0;
                padding: 0.75rem;
                z-index: 35;
                transform: translateX(0);
            }
            
            #leaderboard-container.expanded {
                transform: translateX(260px);
            }
            
            #leaderboard-toggle {
                position: fixed;
                top: 10px;
                left: 10px;
                z-index: 40;
                background: rgba(0, 0, 0, 0.6);
                border: 1px solid rgba(255, 255, 255, 0.2);
                color: white;
                padding: 0.75rem;
                border-radius: 12px;
                font-size: 1.2rem;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
            }
            
            #leaderboard-toggle:hover {
                background: rgba(0, 0, 0, 0.6);
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
            }

            #lang-toggle:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: scale(1.05);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            }
        }

        /* Glass effect overlays */
        .overlay-glass {
            background: rgba(0, 0, 0, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }

        .overlay-glass h2 {
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.7) !important;
        }

        .overlay-glass input {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
        }

        .overlay-glass button {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
            transition: all 0.3s ease !important;
        }

        .overlay-glass button:hover {
            background: rgba(255, 255, 255, 0.2) !important;
            transform: scale(1.05) !important;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4) !important;
        }
    </style>
</head>

<body class="bg-gray-900 text-white flex flex-col items-center justify-center min-h-screen p-4" dir="ltr">

    <!-- Leaderboard Toggle Button -->
    <button id="leaderboard-toggle">
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
        <h1 id="title" class="text-4xl font-game text-green-400 text-content">SNAKE</h1>
        <p id="subtitle" class="text-gray-400 mt-2 text-content">Swipe anywhere to control the snake</p>
        <div class="mt-4 text-2xl font-game flex justify-center items-center gap-8 score-level">
            <div class="text-content"><span id="score-label">SCORE</span>: <span id="score" class="text-yellow-400">0</span></div>
            <div class="text-content"><span id="level-label">LEVEL</span>: <span id="level" class="text-cyan-400">1</span></div>
        </div>
    </div>

    <!-- Canvas and Overlays Container -->
    <div id="canvas-container" class="relative">
        <!-- Name Entry Overlay -->
        <div id="name-entry-overlay"
            class="absolute inset-0 overlay-glass flex flex-col items-center justify-center rounded-lg text-center p-4 z-20">
            <h2 id="name-entry-title" class="text-4xl font-game text-yellow-400 mb-6 text-content">ENTER YOUR NAME</h2>
            <input type="text" id="player-name-input"
                class="input-field text-white text-xl p-4 rounded-lg mb-6 w-80 max-w-full text-center transition-all duration-200"
                placeholder="Your Name" maxlength="20" autocomplete="off" spellcheck="false">
            <div class="flex flex-col sm:flex-row gap-4">
                <button id="confirm-name-button"
                    class="text-white font-bold py-3 px-8 rounded-lg shadow-lg transition-transform transform font-game text-lg disabled:opacity-50 disabled:hover:scale-100 text-content"
                    disabled>
                    CONTINUE
                </button>
                <button id="guest-button"
                    class="text-white font-bold py-3 px-8 rounded-lg shadow-lg transition-transform transform font-game text-lg text-content">
                    PLAY AS GUEST
                </button>
            </div>
        </div>

        <div id="tutorial-overlay"
            class="absolute inset-0 overlay-glass flex flex-col items-center justify-center rounded-lg text-center p-4 z-10 hidden">
            <h2 id="tutorial-title" class="text-4xl font-game text-yellow-400 mb-6 text-content">HOW TO PLAY</h2>
            <p id="tutorial-text" class="text-xl text-gray-200 mb-8 text-content">Swipe anywhere on the screen to guide the snake.
            </p>
            <button id="start-button"
                class="text-white font-bold py-3 px-8 rounded-lg shadow-lg transition-transform transform font-game text-lg text-content">
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
            <p class="mt-4 text-xl text-gray-300 text-content"><span id="final-score-label">Your score</span>: <span id="final-score"
                    class="font-bold text-yellow-300">0</span></p>
            <button id="restart-button"
                class="mt-6 text-white font-bold py-3 px-6 rounded-lg shadow-lg transition-transform transform font-game text-sm text-content">
                RESTART
            </button>
        </div>

        <!-- Replay Overlay -->
        <div id="replay-overlay"
            class="absolute inset-0 overlay-glass flex flex-col items-center justify-center rounded-lg text-center p-4 z-15 hidden">
            <h2 id="replay-title" class="text-3xl font-game text-cyan-400 mb-4">REPLAY MODE</h2>
            <p id="replay-info" class="text-lg text-gray-300 mb-4">Replaying game...</p>
            <div class="flex gap-4 mb-4">
                <button id="replay-pause-button"
                    class="text-white font-bold py-2 px-4 rounded-lg shadow-lg transition-transform transform font-game text-sm">
                    PAUSE
                </button>
                <button id="replay-speed-button"
                    class="text-white font-bold py-2 px-4 rounded-lg shadow-lg transition-transform transform font-game text-sm">
                    SPEED: 1x
                </button>
            </div>
            <button id="replay-exit-button"
                class="text-white font-bold py-2 px-4 rounded-lg shadow-lg transition-transform transform font-game text-sm">
                EXIT REPLAY
            </button>
        </div>
    </div>

    <script>
        // --- LANGUAGE & UI TEXT ---
        const translations = {
            en: {
                title: 'SNAKE',
                subtitle: 'Swipe anywhere to control the snake',
                scoreLabel: 'SCORE',
                levelLabel: 'LEVEL',
                gameOverTitle: 'GAME OVER',
                finalScoreLabel: 'Your score',
                restartButton: 'RESTART',
                langToggle: 'عربي',
                tutorialTitle: 'HOW TO PLAY',
                tutorialText: 'Swipe anywhere on the screen to guide the snake.',
                startButton: 'START GAME',
                credits: 'Created by Gemini',
                copyright: '&copy; 2024. All Rights Reserved.',
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
                title: 'الثعبان',
                subtitle: 'اسحب في أي مكان للتحكم في الثعبان',
                scoreLabel: 'النتيجة',
                levelLabel: 'المستوى',
                gameOverTitle: 'انتهت اللعبة',
                finalScoreLabel: 'نتيجتك',
                restartButton: 'إعادة البدء',
                langToggle: 'English',
                tutorialTitle: 'كيفية اللعب',
                tutorialText: 'اسحب في أي مكان على الشاشة لتوجيه الثعبان.',
                startButton: 'ابدأ اللعبة',
                credits: 'برمجة بواسطة Gemini',
                copyright: '&copy; 2024. جميع الحقوق محفوظة.',
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
            document.getElementById('game-over-title').textContent = t.gameOverTitle;
            document.getElementById('final-score-label').innerHTML = t.finalScoreLabel;
            document.getElementById('restart-button').textContent = t.restartButton;
            document.getElementById('lang-toggle').textContent = t.langToggle;
            document.getElementById('tutorial-title').textContent = t.tutorialTitle;
            document.getElementById('tutorial-text').textContent = t.tutorialText;
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
            let boxSize = 20,
                cols, rows, snake, food;
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

            // Replay variables
            let isReplayMode = false;
            let replayData = null;
            let replayMoveIndex = 0;
            let replaySpeed = 1;
            let replayPaused = false;
            let replaySnake = null;
            let replayStartTime = null;
            let replayInitialTimestamp = null;

            // DOM Elements
            const scoreEl = document.getElementById('score');
            const levelEl = document.getElementById('level');
            const finalScoreEl = document.getElementById('final-score');
            const gameOverEl = document.getElementById('game-over');
            const restartButton = document.getElementById('restart-button');
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
            const replayExitButton = document.getElementById('replay-exit-button');
            const replayInfoEl = document.getElementById('replay-info');
            const leaderboardContainer = document.getElementById('leaderboard-container');
            const leaderboardToggle = document.getElementById('leaderboard-toggle');
            
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

                // Event listeners
                restartButton.addEventListener('click', resetGame);
                startButton.addEventListener('click', startGame);
                confirmNameButton.addEventListener('click', proceedToTutorial);
                guestButton.addEventListener('click', playAsGuest);

                // Multiple input event listeners for better compatibility
                playerNameInput.addEventListener('input', validateNameInput);
                playerNameInput.addEventListener('keyup', validateNameInput);
                playerNameInput.addEventListener('change', validateNameInput);
                playerNameInput.addEventListener('paste', () => {
                    setTimeout(validateNameInput, 10); // Delay to allow paste to complete
                });

                playerNameInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !confirmNameButton.disabled) {
                        proceedToTutorial();
                    }
                });

                // Add click event to the name entry overlay to focus input
                nameEntryOverlay.addEventListener('click', (e) => {
                    if (e.target === nameEntryOverlay) {
                        playerNameInput.focus();
                    }
                });

                langToggleButton.addEventListener('click', () => setLanguage(currentLang === 'en' ? 'ar' : 'en'));

                // Replay controls
                replayPauseButton.addEventListener('click', toggleReplayPause);
                replaySpeedButton.addEventListener('click', cycleReplaySpeed);
                replayExitButton.addEventListener('click', exitReplay);

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
                // Clear canvas for transparency now that backdrop blur is removed
                p.clear();
                if (isReplayMode) {
                    handleReplayMode();
                    if (replaySnake) replaySnake.show();
                } else {
                    if (!isGameOver) {
                        snake.update();
                        snake.checkCollision();
                    }
                    snake.show();
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
                    checkRankAndConfetti();
                    if (score > 0 && score % 5 === 0) {
                        level++;
                        levelEl.textContent = level;
                        p.frameRate(10 + level * 2);
                    }
                }
            };

            p.touchStarted = () => {
                if (!isGameOver && !isReplayMode) {
                    touchStartX = p.mouseX;
                    touchStartY = p.mouseY;
                }
                return false;
            }

            p.touchEnded = () => {
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
                }
                return false;
            }

            p.keyPressed = () => {
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
                // Clear any previous input
                playerNameInput.value = '';
                confirmNameButton.disabled = true;

                // Hide leaderboard on mobile during name entry
                setLeaderboardVisibility(false);

                // Play intro splash sound
                if (introSplashSound) {
                    introSplashSound.currentTime = 0;
                    introSplashSound.play().catch(e => console.log("Sound play prevented by browser:", e));
                }

                // Focus the input field after a short delay to ensure it's visible
                setTimeout(() => {
                    playerNameInput.focus();
                }, 100);

                gsap.to("#name-entry-overlay", {
                    duration: 0.8,
                    opacity: 0.95,
                    repeat: -1,
                    yoyo: true,
                    ease: "power1.inOut"
                });
            }

            function validateNameInput() {
                const name = playerNameInput.value.trim();
                const isValid = name.length >= 2;
                confirmNameButton.disabled = !isValid;

                // Visual feedback
                if (name.length > 0) {
                    if (isValid) {
                        playerNameInput.style.borderColor = '#10b981'; // green
                        playerNameInput.style.backgroundColor = '#374151'; // lighter gray
                    } else {
                        playerNameInput.style.borderColor = '#f59e0b'; // yellow/orange
                        playerNameInput.style.backgroundColor = '#1f2937'; // darker gray
                    }
                } else {
                    playerNameInput.style.borderColor = '#4b5563'; // default gray
                    playerNameInput.style.backgroundColor = '#1f2937'; // default dark gray
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
                gsap.to(nameEntryOverlay, {
                    opacity: 0,
                    duration: 0.5,
                    onComplete: () => {
                        nameEntryOverlay.style.display = 'none';
                        tutorialOverlay.style.display = 'flex';
                        setupTutorial();
                    }
                });
            }

            function proceedToTutorial() {
                playerName = playerNameInput.value.trim();
                if (playerName.length < 2) return;

                gsap.killTweensOf("#name-entry-overlay");
                gsap.to(nameEntryOverlay, {
                    opacity: 0,
                    duration: 0.5,
                    onComplete: () => {
                        nameEntryOverlay.style.display = 'none';
                        tutorialOverlay.style.display = 'flex';
                        setupTutorial();
                    }
                });
            }

            function logMove(newDirection) {
                if (!gameId || isReplayMode) return;
                const move = {
                    game_id: gameId,
                    move_sequence: moveSequence++,
                    direction: newDirection,
                    timestamp_ms: Date.now() - gameStartTime,
                    snake_length: snake.body.length,
                    food_x: food ? food.x / boxSize : 0,
                    food_y: food ? food.y / boxSize : 0
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
                    }
                } catch (error) {
                    console.error('Failed to save game:', error);
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
                    replayMoveIndex = 0;
                    replayPaused = false;
                    replaySpeed = 1;
                    replayStartTime = null;
                    replayInitialTimestamp = null;

                    // Hide game UI and show replay UI
                    replayOverlay.style.display = 'flex';
                    replayInfoEl.textContent = `Replaying ${replayData.game.player_name}'s game (Score: ${replayData.game.score})`;

                    // Initialize replay snake with correct position and length
                    if (replayData.moves && replayData.moves.length > 0) {
                        const firstMove = replayData.moves[0];
                        replaySnake = new Snake();
                        // Initialize snake at the correct starting position
                        replaySnake.body = [p.createVector(
                            Math.floor(cols / 2) * boxSize, 
                            Math.floor(rows / 2) * boxSize
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
                    p.frameRate(10 * replaySpeed);
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
                        
                        // Handle snake growth - if current length is greater than previous, snake ate food
                        // We need to grow the snake until it reaches the correct length
                        while (replaySnake.body.length < currentMove.snake_length) {
                            replaySnake.grow();
                        }
                        
                        replayMoveIndex++;
                        
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
                p.frameRate(Math.max(1, 10 * replaySpeed));
            }

            function exitReplay() {
                isReplayMode = false;
                replayData = null;
                replaySnake = null;
                replayMoveIndex = 0;
                replayPaused = false;
                replaySpeed = 1;
                replayStartTime = null;
                replayInitialTimestamp = null;
                replayOverlay.style.display = 'none';
                
                // Reset game state
                isGameOver = false;
                score = 0;
                level = 1;
                
                // Re-initialize the game
                snake = new Snake();
                placeFood();
                
                // Reset UI
                scoreEl.textContent = score;
                levelEl.textContent = level;
                
                // Restart the game loop
                p.frameRate(10);
                p.loop();

                // Return to name entry
                nameEntryOverlay.style.display = 'flex';
                setupNameEntry();
            }

            function setupTutorial() {
                setLeaderboardVisibility(false);
                gsap.to("#tutorial-overlay", {
                    duration: 0.8,
                    opacity: 0.9,
                    repeat: -1,
                    yoyo: true,
                    ease: "power1.inOut"
                });
            }

            function startGame() {
                gsap.killTweensOf("#tutorial-overlay");
                
                // Play intro splash sound on game start
                if (introSplashSound) {
                    introSplashSound.currentTime = 0;
                    introSplashSound.play().catch(e => console.log("Sound play prevented by browser:", e));
                }
                
                gsap.to(tutorialOverlay, {
                    opacity: 0,
                    duration: 0.5,
                    onComplete: () => {
                        tutorialOverlay.style.display = 'none';
                        initializeNewGame();
                    }
                });
            }

            function initializeNewGame() {
                resetGame();
                setLeaderboardVisibility(true);
                gameStartTime = Date.now();
                moveSequence = 0;
                gameMoves = [];
                // Make sure we have a player name, generate one if needed
                if (!playerName) {
                    playerName = generateRandomName();
                }
                saveGameData(); // Save initial game state and get game ID
            }

            function placeFood() {
                let newFoodPos = p.createVector(p.floor(p.random(cols)), p.floor(p.random(rows)));
                newFoodPos.mult(boxSize);
                while (snake.isOnSnake(newFoodPos)) {
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

            function gameOver() {
                if (isReplayMode || isGameOver) return;
                isGameOver = true;
                setLeaderboardVisibility(false);
                // Animate final score with GSAP
                gsap.to(finalScoreEl, {
                    duration: 1,
                    innerHTML: score,
                    snap: { innerHTML: 1 },
                    ease: "power2.out"
                });
                gameOverEl.style.display = 'flex';
                gsap.fromTo(gameOverEl, {
                    opacity: 0,
                    scale: 0.8
                }, {
                    opacity: 1,
                    scale: 1,
                    duration: 0.5,
                    ease: 'power2.out'
                });
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
                // Provide exit strategy: allow restart or return to name entry
                restartButton.onclick = () => {
                    gameOverEl.style.display = 'none';
                    nameEntryOverlay.style.display = 'flex';
                    setupNameEntry();
                };
                p.noLoop();
            }

            function resetGame() {
                if (isReplayMode) {
                    exitReplay();
                    return;
                }

                isGameOver = false;
                score = 0;
                level = 1;
                direction = 'right';
                playerRank = Infinity;
                particles = [];
                moveSequence = 0;
                gameMoves = [];
                gameId = null;

                // Animate score reset with GSAP
                gsap.to(scoreEl, {
                    duration: 0.5,
                    innerHTML: score,
                    snap: { innerHTML: 1 },
                    ease: "power2.out"
                });
                levelEl.textContent = level;
                gameOverEl.style.display = 'none';

                snake = new Snake();
                placeFood();
                p.loop();
                p.frameRate(10);
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

            // --- Snake Class (Unchanged) ---
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
                    if (head.x >= p.width || head.x < 0 || head.y >= p.height || head.y < 0) {
                        gameOver();
                    }
                    for (let i = 0; i < this.body.length - 1; i++) {
                        let part = this.body[i];
                        if (part.x === head.x && part.y === head.y) {
                            gameOver();
                        }
                    }
                }
                show() {
                    for (let i = 0; i < this.body.length; i++) {
                        p.fill(i === this.body.length - 1 ? 'rgb(45, 212, 191)' : 'rgb(16, 185, 129)');
                        p.noStroke();
                        p.rect(this.body[i].x, this.body[i].y, boxSize, boxSize, 4);
                    }
                }
            }

        };

        new p5(sketch);
    </script>
</body>

</html>