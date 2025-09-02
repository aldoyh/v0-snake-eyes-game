# Snake Eyes Game - Project Context

## Project Overview

This is a "Snake Eyes" game, a simple implementation of the classic Snake game. It is developed using PHP for the backend logic and database interactions, and p5.js (a JavaScript library) for the frontend game rendering. The project also uses Tailwind CSS for styling and several external libraries like GSAP for animations and Canvas Confetti for visual effects.

Key features include:
- Touch and keyboard controls
- Score tracking with a local SQLite database
- Leaderboard display
- Multi-language support (English/Arabic)
- Game replay functionality
- Visual effects (particles, animations)

## Building and Running

### Prerequisites
- PHP installed on your system (version 7.0 or higher recommended)
- A web browser to play the game

### Running the Game
The project includes a `Run.sh` script to start a local PHP development server:
1.  Make the script executable: `chmod +x Run.sh`
2.  Run the script: `./Run.sh`
3.  This will start a PHP built-in server, usually on `http://localhost:<port>`, serving files from the `public` directory.
4.  Open your web browser and navigate to the address shown in the terminal.

The main game is accessed via `public/index.php`. This file contains both the backend API logic (for saving scores, fetching leaderboards, handling replays) and the frontend HTML/CSS/JavaScript code.

## Development Conventions

- **Single File Architecture**: The main game logic (`index.php`) combines both frontend and backend code. API endpoints are handled at the top of the file when specific POST or GET requests are made.
- **Database**: Uses SQLite (`game_scores.db`) for storing game data. It has tables for `games`, `game_moves` (for replay functionality), and a legacy `scores` table.
- **Frontend Framework**: Uses p5.js for canvas-based rendering of the game. Tailwind CSS is used for most UI styling, with some custom CSS for specific elements and RTL (right-to-left) language support.
- **External Libraries**:
  - p5.js: Game rendering.
  - Tailwind CSS: UI styling.
  - GSAP: Animations and transitions.
  - Canvas Confetti: Celebration effects.
- **API Endpoints**: The `index.php` file acts as an API endpoint for:
  - Saving game data (`action=save_game`)
  - Saving individual moves (`action=save_move`)
  - Fetching leaderboard data (`action=get_leaderboard`)
  - Fetching replay data (`action=get_replay`)
  - Legacy score saving (direct POST data).
- **Code Structure**: The JavaScript code within `index.php` is structured with:
  - Language and UI text management.
  - p5.js sketch setup and draw loop.
  - Game logic functions (setup, input handling, game state updates).
  - API interaction functions.
  - UI update functions.
  - Helper classes (e.g., `Particle`).
  - The `Snake` class definition.
