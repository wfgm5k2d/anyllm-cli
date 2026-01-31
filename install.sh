#!/bin/sh
set -e

# This script downloads and installs the 'anyllm' CLI tool.
# It attempts to place it in /usr/local/bin, which may require sudo.

echo "Installing anyllm CLI..."

# --- Helper Functions ---
detect_os() {
    case "$(uname -s)" in
        Linux)
            echo "linux"
            ;;
        Darwin)
            echo "macos"
            ;;
        *)
            echo "Unsupported OS: $(uname -s)" >&2
            exit 1
            ;;
    esac
}

detect_arch() {
    case "$(uname -m)" in
        x86_64 | amd64)
            echo "x86_64"
            ;;
        arm64 | aarch64)
            echo "aarch64"
            ;;
        *)
            echo "Unsupported architecture: $(uname -m)" >&2
            exit 1
            ;;
    esac
}

# --- Main Logic ---
OS=$(detect_os)
ARCH=$(detect_arch)
TARGET_DIR="/usr/local/bin"
TARGET_FILE="$TARGET_DIR/anyllm"
LATEST_RELEASE_URL="https://github.com/wfgm5k2d/anyllm-cli/releases/latest/download"
BINARY_NAME="anyllm-${OS}-${ARCH}"
DOWNLOAD_URL="${LATEST_RELEASE_URL}/${BINARY_NAME}"

# Check if target directory exists and is writable
if [ ! -d "$TARGET_DIR" ]; then
    echo "Error: Target directory $TARGET_DIR does not exist." >&2
    exit 1
fi

echo "Detected OS: $OS"
echo "Detected Arch: $ARCH"
echo "Downloading from: $DOWNLOAD_URL"

# Download the binary to a temporary file
TMP_FILE=$(mktemp)
if ! curl -fsSL -o "$TMP_FILE" "$DOWNLOAD_URL"; then
    echo "Error: Failed to download binary." >&2
    rm "$TMP_FILE"
    exit 1
fi
echo "Download complete."

# Make the temporary file executable
chmod +x "$TMP_FILE"

# Move the binary to the target directory. This may require sudo.
echo "Attempting to install to $TARGET_FILE..."
if command -v sudo >/dev/null; then
    if ! sudo mv "$TMP_FILE" "$TARGET_FILE"; then
        echo "Error: Failed to move binary to $TARGET_FILE with sudo." >&2
        echo "Please try running the script with sudo, or move the file manually." >&2
        # Clean up by removing the temp file if the move fails
        sudo rm "$TMP_FILE" >/dev/null 2>&1 || rm "$TMP_FILE" >/dev/null 2>&1
        exit 1
    fi
else
    if ! mv "$TMP_FILE" "$TARGET_FILE"; then
        echo "Error: 'sudo' not found. Failed to move binary to $TARGET_FILE." >&2
        echo "Please move the file manually: mv $TMP_FILE $TARGET_FILE" >&2
        # Clean up by removing the temp file if the move fails
        rm "$TMP_FILE" >/dev/null 2>&1
        exit 1
    fi
fi

echo ""
echo "✅ anyllm has been installed successfully to $TARGET_FILE"
echo "Run 'anyllm' to get started."

# Final check for macOS Gatekeeper
if [ "$OS" = "macos" ]; then
    echo ""
    echo "NOTE FOR MACOS USERS:"
    echo "When you first run 'anyllm', you may need to grant permission in:"
    echo "System Settings > Privacy & Security."
fi
