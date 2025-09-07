#!/bin/bash

# Function to find a free port
find_free_port() {
    # Try port 8000 first
    if ! lsof -i :8000 > /dev/null 2>&1; then
        echo 8000
        return
    fi
    
    # Try ports 8001-8010
    for port in {8001..8010}; do
        if ! lsof -i :$port > /dev/null 2>&1; then
            echo $port
            return
        fi
    done
    
    # If no ports found, use a random port
    echo "No free ports found in the preferred range. Please manually specify a port."
    exit 1
}

# Find a free port
PORT=$(find_free_port)

echo "Starting server on port $PORT..."
# Record the chosen port so cli.php can display it
echo "$PORT" > .server_port
php -S localhost:$PORT -t public/