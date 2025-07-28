<?php

namespace SilverStripe\Core\Tests;

use SilverStripe\Core\TempFolder;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Director;

/**
 * Tests for the core of SilverStripe, such as how the temporary
 * directory is determined throughout the framework.
 */
class TempFolderTest extends SapphireTest
{

    protected $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = Director::baseFolder() . DIRECTORY_SEPARATOR . 'silverstripe-cache';
    }

    public function testGetTempPathInProject()
    {
        // TempFolder::getTempFolderUsername does not include the php version
        // but TempFolder::getTempFolder will always return a folder including the php version
        $user = TempFolder::getTempFolderUsername();
        $version = preg_replace('/[^\w\-\.+]+/', '-', PHP_VERSION);
        $userVersion = $user . '-' . $version;

        // Test cache with local folder if it exists (silverstripe-cache in base path)
        if (is_dir($this->tempPath)) {
            $localPath = TempFolder::getTempFolder(BASE_PATH);
            $this->assertEquals($localPath, $this->tempPath . DIRECTORY_SEPARATOR . $userVersion);
        }

        // Test cache when stored in system folder (silverstripe-cache-$base folder)
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'silverstripe-cache';

        // A typical Windows location for where sites are stored on IIS
        $this->assertEquals(
            $base . 'C--inetpub-wwwroot-silverstripe-test-project' . DIRECTORY_SEPARATOR . $userVersion,
            TempFolder::getTempFolder('C:\\inetpub\\wwwroot\\silverstripe-test-project')
        );

        // A typical Mac OS X location for where sites are stored
        $this->assertEquals(
            $base . '-Users-joebloggs-Sites-silverstripe-test-project' . DIRECTORY_SEPARATOR . $userVersion,
            TempFolder::getTempFolder('/Users/joebloggs/Sites/silverstripe-test-project')
        );

        // A typical Linux location for where sites are stored
        $this->assertEquals(
            $base . '-var-www-silverstripe-test-project' . DIRECTORY_SEPARATOR . $userVersion,
            TempFolder::getTempFolder('/var/www/silverstripe-test-project')
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $user = TempFolder::getTempFolderUsername();
        $version = preg_replace('/[^\w\-\.+]+/', '-', PHP_VERSION);
        $userVersion = $user . '-' . $version;

        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'silverstripe-cache';
        $directories = [
            'C--inetpub-wwwroot-silverstripe-test-project',
            '-Users-joebloggs-Sites-silverstripe-test-project',
            '-cache-var-www-silverstripe-test-project'
        ];
        foreach ($directories as $dir) {
            $path = $base . $dir;

            // Remove temp folder and parent folder
            if (is_dir($path)) {
                rmdir($path . DIRECTORY_SEPARATOR . $userVersion);
                rmdir($path);
            }
        }
    }
}
