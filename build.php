#!/usr/bin/env php
<?php
/**
 * Build script for BinktermPHP Installer PHAR
 *
 * Usage: php build.php
 */

// Check if phar.readonly is disabled
if (ini_get('phar.readonly')) {
    echo "Error: phar.readonly must be disabled in php.ini\n";
    echo "Run with: php -d phar.readonly=0 build.php\n";
    exit(1);
}

$pharFile = 'binkterm-installer.phar';
$buildDir = __DIR__;

// Remove existing PHAR if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

echo "Building PHAR archive...\n";

try {
    $phar = new Phar($pharFile);

    // Start buffering to improve performance
    $phar->startBuffering();

    // Add files to the PHAR
    echo "Adding files...\n";

    // Add main install script
    $phar->addFile($buildDir . '/install.php', 'install.php');
    echo "  + install.php\n";

    // Add source files
    $phar->addFile($buildDir . '/src/ansicliconsole.php', 'src/ansicliconsole.php');
    echo "  + src/ansicliconsole.php\n";

    // Add cron.example
    $phar->addFile($buildDir . '/cron.example', 'cron.example');
    echo "  + cron.example\n";

    // Create a stub (entry point)
    $stub = <<<'STUB'
#!/usr/bin/env php
<?php
/**
 * BinktermPHP Installer PHAR
 */
Phar::mapPhar('binkterm-installer.phar');
require 'phar://binkterm-installer.phar/install.php';
__HALT_COMPILER();
STUB;

    $phar->setStub($stub);

    // Stop buffering and write changes
    $phar->stopBuffering();

    // Make it executable on Unix-like systems
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($pharFile, 0755);
    }

    echo "\nBuild complete!\n";
    echo "PHAR file: $pharFile\n";
    echo "Size: " . number_format(filesize($pharFile)) . " bytes\n";
    echo "\nUsage:\n";
    echo "  php $pharFile [options]\n";
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  ./$pharFile [options]\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
