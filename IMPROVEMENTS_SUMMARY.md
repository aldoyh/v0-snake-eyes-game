# Snake Eyes Game - Improvements Summary

## Changes Made

### 1. Enhanced Name Entry System
- Added a "Play as Guest" button to the name entry overlay
- Implemented random name generation with fun combinations (e.g., "CoolSnake123", "BraveGamer456")
- Made name entry optional - players can now choose to enter their own name or use a randomly generated one

### 2. Fixed Run Script
- Updated Run.sh to automatically find and use a free port (8000-8010 range)
- Added error handling for port availability
- Made the script executable and more robust

### 3. Game Logic Improvements
- Ensured that a player name is always set (either user-provided or randomly generated)
- Added safety checks in game initialization to prevent issues with missing player names
- Maintained all existing game functionality (touch controls, keyboard controls, scoring, levels, etc.)

### 4. UI/UX Enhancements
- Improved name entry overlay with better button layout (side-by-side on larger screens, stacked on mobile)
- Added visual feedback for name input validation
- Maintained all existing animations and visual effects

## Technical Implementation Details

### Random Name Generation
The random name generation function creates names by combining:
- Random adjectives: Cool, Fast, Smart, Brave, Clever, Swift, Bold, Wise, Keen, Ace
- Random nouns: Player, Gamer, Snake, Champion, Master, Hero, Legend, Warrior, Pro, Expert
- A random number (0-999) for uniqueness

### JavaScript Functions Added
1. `generateRandomName()` - Creates a unique player name
2. `playAsGuest()` - Handles the guest player flow
3. Enhanced `initializeNewGame()` - Ensures player name is always set

### HTML/CSS Changes
1. Added "Play as Guest" button to the name entry overlay
2. Improved button layout with flexbox for better responsiveness
3. Maintained consistent styling with the rest of the game

## Testing Performed
- Verified PHP syntax is correct
- Confirmed server starts properly on an available port
- Tested database operations (tables exist, can insert/fetch data)
- Verified API endpoints respond correctly
- Checked that all game functions work as expected

## How to Run the Game
1. Make sure PHP is installed on your system
2. Run `chmod +x Run.sh` to make the script executable
3. Run `./Run.sh` to start the game server
4. Open your browser to `http://localhost:8001` (or the port shown in the terminal)
5. Choose to enter your own name or click "Play as Guest" for a randomly generated name

The game should now be fully functional with the improved name entry system.