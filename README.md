# SNAKE EYES GAME - Enhanced Edition

An upgraded version of the classic Snake game with modern features and enhanced gameplay.

## FEATURES

### Core Gameplay
- Classic snake movement with swipe/touch controls
- Score tracking and level progression
- Collision detection (walls, self, obstacles)

### Enhanced Features
- **Power-up System**: Collect special items for temporary abilities
  - ⚡ Speed Boost: Increase snake movement speed
  - 🛡️ Shield: Temporary invincibility against collisions
  - 🐢 Slow Motion: Decrease game speed for better control
  
- **Obstacle System**: Dynamic obstacles that appear as you level up
- **Visual Effects**: Particle explosions, smooth animations, and modern UI
- **Progressive Difficulty**: Game speeds up and adds obstacles as you level up
- **Responsive Design**: Works on mobile, tablet, and desktop devices

### Social Features
- Leaderboard with top 10 players
- Game replay system to watch your best performances
- Score sharing capabilities

### Localization
- Full English and Arabic language support
- RTL (Right-to-Left) layout support for Arabic

### Audio
- Immersive sound effects for game events
- Background music support

## TECHNOLOGY STACK

- **Frontend**: p5.js for game rendering, Tailwind CSS for UI, GSAP for animations
- **Backend**: PHP for server-side logic and SQLite for data storage
- **Audio**: HTML5 Audio API
- **Visual Effects**: Canvas Confetti for celebrations

## DEVELOPED BY

HASAN ALDOY (@aldoyh)

### SPONSORED BY

GULFMARCOM
BAHRAINOUNA

## HOW TO RUN

1. Make the run script executable:
   ```bash
   chmod +x Run.sh
   ```

2. Run the game:
   ```bash
   ./Run.sh
   ```

3. Open your browser and navigate to the provided localhost address (usually http://localhost:8000)

## IMPROVEMENTS FROM ORIGINAL VERSION

1. **Modern UI/UX**:
   - Glassmorphism design with blur effects
   - Improved color scheme and typography
   - Better responsive layout for all devices
   - Enhanced visual feedback

2. **Gameplay Enhancements**:
   - Added power-up system with three unique abilities
   - Dynamic obstacle generation
   - Progressive difficulty system
   - Snake with eyes for better visual feedback

3. **Technical Improvements**:
   - Better code organization
   - Enhanced performance optimizations
   - Improved touch controls for mobile
   - More robust error handling

4. **Social Features**:
   - Enhanced leaderboard with replay functionality
   - Better player engagement through achievements

5. **Accessibility**:
   - Improved internationalization support
   - Better keyboard navigation
   - Enhanced visual indicators

## FILE STRUCTURE

```
.
├── public/
│   ├── index.php          # Main game file with all frontend and backend logic
│   ├── leaderboard.php    # Leaderboard API endpoint
│   ├── snake-game-bg.jpg  # Background image
│   └── Audio files        # Sound effects (mp3)
├── game_scores.db         # SQLite database for scores
├── Run.sh                 # Startup script
└── README.md              # This file
```

## DATABASE STRUCTURE

The game uses SQLite with three main tables:

1. `games` - Stores game session information
2. `game_moves` - Records each move for replay functionality
3. `scores` - Legacy table for backward compatibility

## API ENDPOINTS

- `POST /` with `action=save_game` - Save game results
- `POST /` with `action=save_move` - Save individual moves
- `GET /?action=get_leaderboard` - Retrieve top scores
- `GET /?action=get_replay&game_id={id}` - Retrieve game replay data

## CUSTOMIZATION

You can customize the game by modifying:
- Colors: Edit the CSS variables in the style section
- Difficulty: Adjust the level progression parameters
- Power-ups: Modify duration and effects in the JavaScript code
- Sounds: Replace the MP3 files with your own

## LICENSE

This project is licensed for personal and educational use.