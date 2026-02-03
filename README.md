# BinktermPHP Installer

This installer is **experimental** and under active development.

The author uses git to manage his boards, so this installer has not been extensively tested in production environments. Use at your own risk and make backups before installing or upgrading.

## Quick Start

Download the pre-built PHAR file and run it:

```bash
# Download the installer
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar

# Run the installer
php binkterm-installer.phar [options]

# Or make it executable (Linux/macOS)
chmod +x binkterm-installer.phar
./binkterm-installer.phar [options]
```

## Usage

```bash
php binkterm-installer.phar [options]

```

### Options

- `--version=X.X.X` - Install specific version (default: latest)
- `--dir=/path` - Installation directory (default: current)
- `--no-color` - Disable colored output
- `--help, -h` - Show help

## Features

- Downloads BinktermPHP from GitHub releases
- Configures database connection
- Sets up initial configuration files
- Supports both new installations and upgrades
- Displays cron/scheduler configuration with actual paths

## Building the PHAR

The PHAR file is automatically built by GitHub Actions when changes are pushed to the main branch.

To build manually from source:

```bash
# Clone the repository
git clone https://github.com/awehttam/binkterm-php-installer.git
cd binkterm-php-installer

# Build the PHAR (requires phar.readonly=0)
php -d phar.readonly=0 build.php

# The binkterm-installer.phar file will be created
```

## Notes

- Always backup your database and configuration files before upgrading
- The installer creates `.env` and `config/binkp.json` files for new installations
- The PHAR file is included in the repository for easy distribution
