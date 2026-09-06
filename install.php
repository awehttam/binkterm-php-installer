#!/usr/bin/env php
<?php
/**
 * BinktermPHP Installer
 *
 * Downloads and configures BinktermPHP from GitHub releases.
 *
 * Usage: php install.php [options]
 *   --version=X.X.X   Install specific version (default: latest)
 *   --dir=/path       Installation directory (default: current)
 *   --help            Show this help
 */

require_once(__DIR__."/src/ansicliconsole.php");

class Installer
{
    const GITHUB_REPO = 'awehttam/binkterm-php';
    const GITHUB_API = 'https://api.github.com/repos/';

    private $version = 'latest';
    private $installDir = '.';
    private $ansi;
    private $composerCmd = null;

    function __construct()
    {
        $this->ansi = new AnsiCliConsole();;
    }
    /**
     * Parse command line arguments
     */
    public function parseArgs(array $argv): void
    {
        foreach ($argv as $arg) {
            if (strpos($arg, '--version=') === 0) {
                $this->version = substr($arg, 10);
            } elseif (strpos($arg, '--dir=') === 0) {
                $this->installDir = substr($arg, 6);
            } elseif ($arg === '--no-color') {
                $this->useColors = false;
            } elseif ($arg === '--help' || $arg === '-h') {
                $this->showHelp();
                exit(0);
            }
        }
    }

    /**
     * Show help
     */
    private function showHelp(): void
    {
        $this->ansi->banner();
        $this->ansi->line($this->ansi->color('  Usage:', AnsiCliConsole::BOLD) . ' php install.php [options]');
        $this->ansi->line();
        $this->ansi->line($this->ansi->color('  Options:', AnsiCliConsole::BOLD));
        $this->ansi->line('    --version=X.X.X   Install specific version (default: latest)');
        $this->ansi->line('    --dir=/path       Installation directory (default: current)');
        $this->ansi->line('    --no-color        Disable colored output');
        $this->ansi->line('    --help, -h        Show this help');
        $this->ansi->line();
    }

    /**
     * Resolve the composer command to use, or return null if not found.
     * Checks (in order): composer in PATH, composer.phar next to this script,
     * composer.phar in the current working directory.
     */
    private function getComposerCommand(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where composer 2>NUL', $output, $result);
        } else {
            exec('which composer 2>/dev/null', $output, $result);
        }

        if ($result === 0) {
            return 'composer';
        }

        if (file_exists(__DIR__ . '/composer.phar')) {
            return PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/composer.phar');
        }

        if (file_exists('composer.phar')) {
            return PHP_BINARY . ' composer.phar';
        }

        return null;
    }

    /**
     * Test database connection
     */
    private function testDatabaseConnection(string $host, string $port, string $dbname, string $user, string $pass): bool
    {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5
            ]);

            // Test the connection
            $pdo->query('SELECT 1');

            return true;
        } catch (\PDOException $e) {
            $this->ansi->error("Database connection failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch all releases from GitHub
     */
    private function fetchAllReleases(): array
    {
        $url = self::GITHUB_API . self::GITHUB_REPO . '/releases';

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: BinktermPHP-Installer\r\n",
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception("Failed to fetch releases from GitHub");
        }

        return json_decode($response, true);
    }

    /**
     * Fetch latest release info from GitHub
     */
    private function fetchReleaseInfo(): array
    {
        $url = self::GITHUB_API . self::GITHUB_REPO . '/releases';

        if ($this->version === 'latest') {
            $url .= '/latest';
        } else {
            $url .= '/tags/' . ltrim($this->version, 'v');
        }

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: BinktermPHP-Installer\r\n",
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception("Failed to fetch release info from GitHub");
        }

        return json_decode($response, true);
    }

    /**
     * Download file with progress
     */
    private function downloadFile(string $url, string $destination): void
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: BinktermPHP-Installer\r\n",
                'timeout' => 300
            ]
        ]);

        // Get file size first
        $headers = get_headers($url, true);
        $size = isset($headers['Content-Length']) ? (int)$headers['Content-Length'] : 0;

        $source = fopen($url, 'rb', false, $context);
        $dest = fopen($destination, 'wb');

        if (!$source || !$dest) {
            throw new \Exception("Failed to open file for download");
        }

        $downloaded = 0;
        while (!feof($source)) {
            $chunk = fread($source, 8192);
            fwrite($dest, $chunk);
            $downloaded += strlen($chunk);

            if ($size > 0) {
                $this->ansi->progressBar($downloaded, $size);
            }
        }

        fclose($source);
        fclose($dest);
    }

    /**
     * Validate installation directory and detect if it's an upgrade
     *
     * @return array ['is_upgrade' => bool, 'valid' => bool]
     */
    private function validateInstallDirectory(string $dir): array
    {
        if (!file_exists($dir)) {
            return ['is_upgrade' => false, 'valid' => true]; // Directory doesn't exist, new install
        }

        if (!is_dir($dir)) {
            throw new \Exception("Installation path exists but is not a directory");
        }

        // Check if directory is empty
        $files = scandir($dir);
        $files = array_diff($files, ['.', '..']);

        if (count($files) === 0) {
            return ['is_upgrade' => false, 'valid' => true]; // Empty directory, new install
        }

        // Directory is not empty - check if it looks like an existing BinktermPHP installation
        $hasComposerJson = file_exists($dir . '/composer.json');
        $hasPublicHtml = is_dir($dir . '/public_html');
        $hasScripts = is_dir($dir . '/scripts');

        if ($hasComposerJson && $hasPublicHtml && $hasScripts) {
            return ['is_upgrade' => true, 'valid' => true]; // Existing installation
        }

        // Directory has files but doesn't look like BinktermPHP
        throw new \Exception("Installation directory is not empty and doesn't appear to be a BinktermPHP installation");
    }

    /**
     * Extract ZIP archive to destination
     */
    private function extractZip(string $zipFile, string $destination): void
    {
        if (!class_exists('ZipArchive')) {
            throw new \Exception("ZIP extension is required but not available");
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \Exception("Failed to open ZIP archive");
        }

        // Create destination directory if it doesn't exist
        if (!file_exists($destination)) {
            mkdir($destination, 0755, true);
        }

        // GitHub zipballs have a root directory like "owner-repo-commit/"
        // We need to extract and strip this root directory
        $rootDir = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if ($rootDir === null && strpos($filename, '/') !== false) {
                $rootDir = substr($filename, 0, strpos($filename, '/') + 1);
            }
        }

        // Extract files, stripping root directory
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Skip the root directory itself
            if ($filename === $rootDir) {
                continue;
            }

            // Strip root directory from path
            $relativePath = $rootDir ? substr($filename, strlen($rootDir)) : $filename;

            // Skip empty paths
            if (empty($relativePath)) {
                continue;
            }

            $targetPath = $destination . '/' . $relativePath;

            // Create directory if this is a directory entry
            if (substr($filename, -1) === '/') {
                if (!file_exists($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                // Extract file
                $dirname = dirname($targetPath);
                if (!file_exists($dirname)) {
                    mkdir($dirname, 0755, true);
                }

                $content = $zip->getFromIndex($i);
                file_put_contents($targetPath, $content);
            }
        }

        $zip->close();
    }

    /**
     * Create .env file from template
     */
    private function createEnvFile(string $installDir, array $config): void
    {
        $templatePath = $installDir . '/.env.example';
        $envPath = $installDir . '/.env';

        if (!file_exists($templatePath)) {
            throw new \Exception(".env.example not found in installation");
        }

        $content = file_get_contents($templatePath);

        // Replace database configuration
        $content = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=' . $config['db_host'], $content);
        $content = preg_replace('/^DB_PORT=.*/m', 'DB_PORT=' . $config['db_port'], $content);
        $content = preg_replace('/^DB_NAME=.*/m', 'DB_NAME=' . $config['db_name'], $content);
        $content = preg_replace('/^DB_USER=.*/m', 'DB_USER=' . $config['db_user'], $content);
        $content = preg_replace('/^DB_PASS=.*/m', 'DB_PASS=' . $config['db_pass'], $content);

        // Generate random admin daemon secret
        $secret = bin2hex(random_bytes(32));
        $content = preg_replace('/^ADMIN_DAEMON_SECRET=.*/m', 'ADMIN_DAEMON_SECRET=' . $secret, $content);

        // Update SITE_URL if provided
        if (!empty($config['site_url'])) {
            $content = preg_replace('/^SITE_URL=.*/m', 'SITE_URL=' . $config['site_url'], $content);
        }

        file_put_contents($envPath, $content);
    }

    /**
     * Create binkp.json config file from template
     */
    private function createBinkpConfig(string $installDir, array $config): void
    {
        $templatePath = $installDir . '/config/binkp.json.example';
        $configPath = $installDir . '/config/binkp.json';

        if (!file_exists($templatePath)) {
            throw new \Exception("config/binkp.json.example not found in installation");
        }

        $template = json_decode(file_get_contents($templatePath), true);

        // Update system configuration
        $template['system']['name'] = $config['system_name'];
        $template['system']['sysop'] = $config['sysop_name'];
        $template['system']['address'] = $config['ftn_address'];

        // Update origin if site URL provided
        if (!empty($config['site_url'])) {
            $template['system']['origin'] = $config['system_name'] . ' - ' . $config['site_url'];
        }

        // Update the first uplink's "me" address to match system address
        if (isset($template['uplinks'][0])) {
            $template['uplinks'][0]['me'] = $config['ftn_address'];
        }

        file_put_contents($configPath, json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    function setupCron($installPath)
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->ansi->error("Windows does not support a cron facility, configure tasks manually.");
            return;
        }

        $cronExamplePath = $installPath . '/cron.example';
        if (!file_exists($cronExamplePath)) {
            $this->ansi->error("Could not find cron.example at: " . $cronExamplePath);
            return;
        }

        $contents = file_get_contents($cronExamplePath);
        if (!$contents) {
            $this->ansi->error("Could not read cron.example");
            return;
        }

        // Remove trailing slash if present
        $installPath = rtrim($installPath, '/\\');

        // Replace the example path with actual installation path
        $contents = str_replace('/path/to/binkterm', $installPath, $contents);

        $temppath = tempnam(sys_get_temp_dir(), "binkweb");
        file_put_contents($temppath, $contents);

        system("crontab ".escapeshellarg($temppath));
        $this->ansi->success("Cron configured");
        return;
    }
    /**
     * Run the installer
     */
    public function run(): int
    {
        $this->ansi->banner();

        $this->ansi->section('System Requirements');

        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '8.0.0', '>=')) {
            $this->ansi->success("PHP $phpVersion");
        } else {
            $this->ansi->error("PHP 8.0+ required (found $phpVersion)");
            return 1;
        }

        // Check required extensions
        $requiredExtensions = ['pdo', 'pdo_pgsql', 'json', 'curl', 'mbstring', 'zip', 'dom', 'openssl','gmp'];
        $errors=0;
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $this->ansi->success("Extension: $ext");
            } else {
                $this->ansi->error("Missing extension: $ext");
                $errors++;
            }
        }

        // Check for composer
        $this->composerCmd = $this->getComposerCommand();
        if ($this->composerCmd !== null) {
            $this->ansi->success("Composer available");
        } else {
            $this->ansi->error("Composer not found (install composer or place composer.phar in current directory)");
            $errors++;
        }

        // Check for 7z (warning only)
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where 7z 2>NUL', $output, $result);
        } else {
            exec('which 7z 2>/dev/null', $output, $result);
        }
        if ($result === 0) {
            $this->ansi->success("7z available");
        } else {
            $this->ansi->line($this->ansi->color("Warning: 7z not found (may be needed for archive extraction)", AnsiCliConsole::YELLOW));
        }

        // Check for unzip (warning only)
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where unzip 2>NUL', $output, $result);
        } else {
            exec('which unzip 2>/dev/null', $output, $result);
        }
        if ($result === 0) {
            $this->ansi->success("unzip available");
        } else {
            $this->ansi->line($this->ansi->color("Warning: unzip not found (may be needed for archive extraction)", AnsiCliConsole::YELLOW));
        }

        if($errors)
            return 1;

        $this->ansi->section('Installation Options');

        // Get installation directory
        $this->installDir = $this->ansi->prompt('Installation directory', $this->installDir);

        // Make path absolute
        $this->installDir = realpath($this->installDir) ?: $this->installDir;

        // Fetch and display available versions
        $this->ansi->info("Fetching available versions from GitHub...");
        try {
            $releases = $this->fetchAllReleases();
            if (is_array($releases) && count($releases) > 0) {
                $this->ansi->line();
                $this->ansi->line($this->ansi->color('Available versions:', AnsiCliConsole::BOLD));

                $pageSize = 5;
                $total = count($releases);
                $shown = 0;
                while ($shown < $total) {
                    $page = array_slice($releases, $shown, $pageSize);
                    foreach ($page as $release) {
                        $version = $release['tag_name'];
                        $prerelease = !empty($release['prerelease']) ? ' (pre-release)' : '';
                        $this->ansi->line("  - {$version}: {$prerelease}");
                    }
                    $shown += count($page);

                    if ($shown < $total) {
                        $remaining = $total - $shown;
                        $answer = strtolower($this->ansi->prompt(
                            "Show more versions? ({$remaining} older) [y/N]"
                        ));
                        if (!in_array($answer, ['y', 'yes'], true)) {
                            $this->ansi->line("  ... {$remaining} older version(s) not shown");
                            break;
                        }
                    }
                }
                $this->ansi->line();
            }
        } catch (\Exception $e) {
            $this->ansi->line($this->ansi->color("Warning: Could not fetch versions from GitHub", AnsiCliConsole::YELLOW));
        }

        // Get version
        $this->version = $this->ansi->prompt('Version to install', $this->version);

        // Check installation directory and detect upgrade
        $isUpgrade = false;
        try {
            $dirCheck = $this->validateInstallDirectory($this->installDir);
            $isUpgrade = $dirCheck['is_upgrade'];

            if ($isUpgrade) {
                $this->ansi->section('Upgrade Detected');
                $this->ansi->line($this->ansi->color('WARNING: Existing installation detected!', AnsiCliConsole::BOLD, AnsiCliConsole::YELLOW));
                $this->ansi->line();
                $this->ansi->line('This will upgrade your existing BinktermPHP installation by:');
                $this->ansi->line('  - Extracting new files and overwriting existing ones');
                $this->ansi->line('  - Updating composer dependencies');
                $this->ansi->line('  - Running database migrations');
                $this->ansi->line();
                $this->ansi->line($this->ansi->color('IMPORTANT: Make backups before proceeding!', AnsiCliConsole::BOLD, AnsiCliConsole::RED));
                $this->ansi->line('  - Back up your database');
                $this->ansi->line('  - Back up your .env file');
                $this->ansi->line('  - Back up your config/ directory');
                $this->ansi->line('  - Back up your data/ directory');
                $this->ansi->line();

                $proceed = $this->ansi->prompt('Have you made backups and want to proceed? (yes/no)', 'no');
                if (strtolower(trim($proceed)) !== 'yes') {
                    $this->ansi->info("Upgrade cancelled - please make backups first");
                    return 0;
                }
            }
        } catch (\Exception $e) {
            $this->ansi->error($e->getMessage());
            return 1;
        }

        // Only collect configuration for new installations
        $config = [];
        if (!$isUpgrade) {
            $this->ansi->section('Configuration');

            // Database configuration with validation loop
            $dbConnected = false;
            $dbHost = 'localhost';
            $dbPort = '5432';
            $dbName = 'binktermphp';
            $dbUser = 'binktermphp';
            $dbPass = '';

            while (!$dbConnected) {
                $dbHost = $this->ansi->prompt('PostgreSQL host', $dbHost);
                $dbPort = $this->ansi->prompt('PostgreSQL port', $dbPort);
                $dbName = $this->ansi->prompt('Database name', $dbName);
                $dbUser = $this->ansi->prompt('Database user', $dbUser);
                $dbPass = $this->ansi->prompt('Database password', $dbPass);

                $this->ansi->info("Testing database connection...");
                $dbConnected = $this->testDatabaseConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

                if ($dbConnected) {
                    $this->ansi->success("Database connection successful!");
                } else {
                    $this->ansi->line();
                    $retry = $this->ansi->prompt('Try again with different settings? (yes/no)', 'yes');
                    if (strtolower(trim($retry)) !== 'yes') {
                        $this->ansi->error("Installation cancelled - database connection required");
                        return 1;
                    }
                    $this->ansi->line();
                }
            }

            // BBS configuration
            $this->ansi->section('BBS Configuration');
            $systemName = $this->ansi->prompt('System name', 'My BBS');
            do {
                $sysopName = $this->ansi->prompt('Sysop name');
                if ($sysopName === '') {
                    $this->ansi->error('Sysop name is required.');
                }
            } while ($sysopName === '');
            $ftnAddress = $this->ansi->prompt('FTN address', '1:999/999');

            // Optional site URL
            $siteUrl = $this->ansi->prompt('Site URL (optional)', '');

            $config = [
                'db_host' => $dbHost,
                'db_port' => $dbPort,
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_pass' => $dbPass,
                'system_name' => $systemName,
                'sysop_name' => $sysopName,
                'ftn_address' => $ftnAddress,
                'site_url' => $siteUrl
            ];
        }

        // Show summary and confirm (only for new installations)
        if (!$isUpgrade) {
            $this->ansi->section('Installation Summary');
            $this->ansi->line("Installation directory: " . $this->installDir);
            $this->ansi->line("Version: " . $this->version);
            $this->ansi->line("Database: " . $config['db_name'] . " on " . $config['db_host'] . ":" . $config['db_port']);
            $this->ansi->line("System name: " . $config['system_name']);
            $this->ansi->line("Sysop: " . $config['sysop_name']);
            $this->ansi->line("FTN Address: " . $config['ftn_address']);
            $this->ansi->line();

            // Prompt whether to proceed with installation
            $confirm = $this->ansi->prompt('Proceed with installation? (yes/no)', 'yes');
            if (strtolower(trim($confirm)) !== 'yes') {
                $this->ansi->info("Installation cancelled");
                return 0;
            }
        }

        $this->ansi->section('Downloading');

        $tempFile = null;
        $installedVersion = null;
        try {
            $this->ansi->info("Fetching release information...");
            $release = $this->fetchReleaseInfo();
            $this->ansi->success("Found version: " . $release['tag_name']);

            // Capture the actual version that was installed (strip 'v' prefix if present)
            $installedVersion = ltrim($release['tag_name'], 'v');

            // Find the zip asset
            $zipUrl = null;
            foreach ($release['assets'] ?? [] as $asset) {
                if (preg_match('/\.zip$/i', $asset['name'])) {
                    $zipUrl = $asset['browser_download_url'];
                    break;
                }
            }

            // Fall back to zipball_url if no asset found
            if (!$zipUrl && isset($release['zipball_url'])) {
                $zipUrl = $release['zipball_url'];
            }

            if (!$zipUrl) {
                throw new \Exception("No download URL found in release");
            }

            $this->ansi->info("Downloading from: $zipUrl");
            $tempFile = sys_get_temp_dir() . '/binkterm-' . uniqid() . '.zip';
            $this->downloadFile($zipUrl, $tempFile);
            $this->ansi->success("Download complete");

            // Extract the archive
            $sectionTitle = $isUpgrade ? 'Upgrading' : 'Installing';
            $this->ansi->section($sectionTitle);
            $this->ansi->info("Extracting archive...");
            $this->extractZip($tempFile, $this->installDir);
            $this->ansi->success("Files extracted");

            // GitHub ZIPs don't preserve executable bits — restore them for shell/PHP scripts
            if (PHP_OS_FAMILY !== 'Windows') {
                foreach (glob($this->installDir . '/scripts/*.{sh,php}', GLOB_BRACE) as $script) {
                    chmod($script, 0755);
                }
            }

            // Only create config files for new installations
            if (!$isUpgrade) {
                // Create .env file
                $this->ansi->info("Creating .env file...");
                $this->createEnvFile($this->installDir, $config);
                $this->ansi->success(".env created");

                // Create binkp.json config
                $this->ansi->info("Creating binkp.json configuration...");
                $this->createBinkpConfig($this->installDir, $config);
                $this->ansi->success("binkp.json created");
            } else {
                $this->ansi->info("Preserving existing configuration files");
            }

            // Clean up temp file
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }

        } catch (\Exception $e) {
            $this->ansi->error($e->getMessage());
            // Clean up temp file on error
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
            return 1;
        }

        // Set up cron (or show instructions) - only for new installations
        if (!$isUpgrade) {
            $this->ansi->section('Cron Setup');
            if (PHP_OS_FAMILY === 'Windows') {
                $this->ansi->info("Windows detected - you'll need to set up scheduled tasks manually");
                $this->ansi->line("See cron.example for the tasks that need to be scheduled");
            } else {
                $setupCron = $this->ansi->prompt('Automatically configure crontab? (yes/no)', 'no');
                if (strtolower(trim($setupCron)) === 'yes') {
                    $this->setupCron($this->installDir);
                } else {
                    $this->ansi->info("You can set up cron manually later");
                    $this->ansi->line("See " . $this->installDir . "/cron.example for scheduled tasks");
                }
            }
        }

        // Database setup
        $this->ansi->section('Database Setup');

        // For upgrades, only ask about running migrations
        if ($isUpgrade) {
            $runDbSetup = $this->ansi->prompt('Run composer install and database migrations? (yes/no)', 'yes');
        } else {
            $runDbSetup = $this->ansi->prompt('Do you want me to proceed with the database setup for you? (yes/no)', 'yes');
        }

        $dbSetupComplete = false;

        if (strtolower(trim($runDbSetup)) === 'yes') {
            try {
                // Change to installation directory
                $oldDir = getcwd();
                chdir($this->installDir);

                $composerCmd = $this->composerCmd;
                if ($composerCmd === null) {
                    throw new \Exception("Composer not found. Please install composer first.");
                }

                // Run composer install
                $this->ansi->info("Installing dependencies with composer...");
                passthru($composerCmd . ' install --no-dev --optimize-autoloader', $composerResult);

                if ($composerResult !== 0) {
                    throw new \Exception("Composer install failed with exit code: " . $composerResult);
                }
                $this->ansi->success("Dependencies installed");

                // Run setup.php
                $this->ansi->info("Running setup.php...");
                $setupScript = getcwd() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'setup.php';
                if (!file_exists($setupScript)) {
                    throw new \Exception("setup.php not found at: " . $setupScript);
                }

                passthru(PHP_BINARY . ' ' . escapeshellarg($setupScript), $setupResult);

                if ($setupResult !== 0) {
                    throw new \Exception("setup.php failed with exit code: " . $setupResult);
                }
                $this->ansi->success("Database setup complete");

                // Restore directory
                chdir($oldDir);
                $dbSetupComplete = true;

            } catch (\Exception $e) {
                $this->ansi->error("Database setup failed: " . $e->getMessage());
                if (isset($oldDir)) {
                    chdir($oldDir);
                }

                $this->ansi->info("You can run the database setup manually:");
                $this->ansi->line("  cd " . $this->installDir);
                $this->ansi->line("  composer install --no-dev --optimize-autoloader");
                $this->ansi->line("  php scripts/setup.php");
            }
        } else {
            $this->ansi->info("Skipping database setup");
        }

        // Installation/Upgrade complete!
        $this->ansi->section('Complete');
        if ($isUpgrade) {
            $this->ansi->success("BinktermPHP has been upgraded!");
        } else {
            $this->ansi->success("BinktermPHP has been installed!");
        }
        $this->ansi->line();

        // Run restart_daemons.sh if present
        $restartScript = $this->installDir . '/scripts/restart_daemons.sh';
        if (file_exists($restartScript)) {
            $this->ansi->info("Restarting daemons...");
            system('bash ' . escapeshellarg($restartScript), $restartResult);
            if ($restartResult === 0) {
                $this->ansi->success("Daemons restarted");
            } else {
                $this->ansi->error("restart_daemons.sh exited with code $restartResult");
            }
            $this->ansi->line();
        }

        ob_start();

        if ($dbSetupComplete) {
            $this->ansi->info("Next steps:");
            $this->ansi->line("    1. Configure your web server to point to:");
            $this->ansi->line("       " . $this->installDir . "/public_html/");
            $this->ansi->line("    2. Edit config/binkp.json to configure your uplinks");
            $this->ansi->line("    3. Set up cron or your preferred service supervisor");
        } else {
            $this->ansi->info("Next steps:");
            $this->ansi->line("    1. cd " . $this->installDir);
            $this->ansi->line("    2. Run: composer install --no-dev --optimize-autoloader");
            $this->ansi->line("       (This will install PHP dependencies)");
            $this->ansi->line("    3. Run: php scripts/setup.php");
            $this->ansi->line("       (This will create the database schema and admin user)");
            $this->ansi->line("    4. Configure your web server to point to public_html/");
            $this->ansi->line("    5. Edit config/binkp.json to configure your uplinks");
            $this->ansi->line("    6. Set up cron or your preferred service supervisor");
        }
        $this->ansi->line();

        // Display cron example (new installations only)
        if (!$isUpgrade) {
            $this->ansi->section('Cron/Scheduler Configuration');

            if (PHP_OS_FAMILY === 'Windows') {
                $this->ansi->line("Set up the equivalent to these cron rules using some sort of scheduling mechanism (like Task Scheduler):");
            } else {
                $this->ansi->line("Add these entries to your crontab (or configure equivalent in your service supervisor):");
            }
            $this->ansi->line();

            // Read and display cron.example with path substitution
            $cronExamplePath = __DIR__ . '/cron.example';
            if (file_exists($cronExamplePath)) {
                $cronContent = file_get_contents($cronExamplePath);
                $cronContent = str_replace('/path/to/binkterm', $this->installDir, $cronContent);

                $this->ansi->line($this->ansi->color($cronContent, AnsiCliConsole::CYAN));

                $this->ansi->line();

                if (PHP_OS_FAMILY === 'Windows') {
                    $this->ansi->info("Use Windows Task Scheduler or another scheduling tool to run these scripts at the specified intervals");
                } else {
                    $this->ansi->info("To install these cron jobs, run:");
                    $this->ansi->line("  crontab -e");
                    $this->ansi->line();
                    $this->ansi->info("Or use a service supervisor like systemd, supervisord, or pm2");
                }
            } else {
                $this->ansi->error("cron.example file not found in installer directory");
                $this->ansi->line("Please refer to the documentation for scheduler setup instructions.");
            }
            $this->ansi->line();
        }

        // Web server configuration (new installations only)
        if (!$isUpgrade) {
            $this->ansi->section('Web Server Configuration');
            $this->ansi->line("Configure your web server to serve BinktermPHP from the public_html directory.");
            $this->ansi->line();
            $this->ansi->line($this->ansi->color("Apache Example Configuration:", AnsiCliConsole::BOLD));
            $this->ansi->line();

            $apacheConfig = <<<APACHE
<VirtualHost *:80>
    ServerName your-bbs-domain.com
    DocumentRoot {$this->installDir}/public_html

    <Directory {$this->installDir}/public_html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Enable PHP if using mod_php
        <IfModule mod_php.c>
            php_flag display_errors Off
            php_value error_log {$this->installDir}/logs/php_errors.log
        </IfModule>
    </Directory>

    # Deny access to sensitive directories
    <DirectoryMatch "^{$this->installDir}/(config|data|scripts|vendor)">
        Require all denied
    </DirectoryMatch>

    ErrorLog {$this->installDir}/logs/binkterm_error.log
    CustomLog {$this->installDir}/logs/binkterm_access.log combined

    # For HTTPS (recommended), also configure SSL:
    # SSLEngine on
    # SSLCertificateFile /path/to/cert.pem
    # SSLCertificateKeyFile /path/to/key.pem
</VirtualHost>
APACHE;

            $this->ansi->line($this->ansi->color($apacheConfig, AnsiCliConsole::CYAN));
            $this->ansi->line();
            $this->ansi->info("After configuring your web server, restart it to apply the changes:");
            $this->ansi->line("  sudo systemctl restart apache2   (Debian/Ubuntu)");
            $this->ansi->line("  sudo systemctl restart httpd     (RHEL/CentOS)");
            $this->ansi->line();
        }

        // Thank you message
        $this->ansi->section('Thank You!');
        $this->ansi->success("Thank you for installing BinktermPHP!");
        $this->ansi->line();
        $this->ansi->line("We'd love to hear from you! Visit us at:");
        $this->ansi->line($this->ansi->color("  https://claudes.lovelybits.org", AnsiCliConsole::BOLD, AnsiCliConsole::GREEN));
        $this->ansi->line();
        $this->ansi->line("Say hi, share your setup, or ask questions.");
        $this->ansi->line();

        // Check for version-specific upgrading guide (only for upgrades)
        if ($isUpgrade && $installedVersion) {
            $docsDir = $this->installDir . '/docs';
            $upgradingFile = $docsDir . '/UPGRADING_' . $installedVersion . '.md';

            if (file_exists($upgradingFile)) {
                $this->ansi->section('Important: Upgrading Guide');
                $this->ansi->line($this->ansi->color('Additional upgrade steps may be required!', AnsiCliConsole::BOLD, AnsiCliConsole::YELLOW));
                $this->ansi->line();
                $this->ansi->line("Please read the upgrading guide for version " . $installedVersion . ":");
                $this->ansi->line("  " . $upgradingFile);
                $this->ansi->line();
            }
        }
        $postInstallOutput = ob_get_clean();
        $postInstallOutput = preg_replace('/\033\[[0-9;]*[mGKHF]/u', '', $postInstallOutput);

        $tmpFile = tempnam(sys_get_temp_dir(), 'binkterm_');
        file_put_contents($tmpFile, $postInstallOutput);
        system('more ' . escapeshellarg($tmpFile));
        unlink($tmpFile);

        return 0;
    }
}

// Run installer
$installer = new Installer();
$installer->parseArgs($argv);
exit($installer->run());
