<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../installer-cleanup.php';

class InstallerCleanupTest extends TestCase
{
    public function test_successful_cleanup_removes_installer_file(): void
    {
        $directory = sys_get_temp_dir().'/bg-portal-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $installer = $directory.'/install.php';
        file_put_contents($installer, '<?php');

        try {
            $this->assertTrue(bgPortalInstallerCanSelfDelete($installer));
            $this->assertTrue(bgPortalDeleteInstaller($installer));
            $this->assertFileDoesNotExist($installer);
        } finally {
            @unlink($installer);
            rmdir($directory);
        }
    }

    public function test_missing_installer_cannot_report_success(): void
    {
        $this->assertFalse(bgPortalDeleteInstaller(sys_get_temp_dir().'/not-existing-install-file-'.bin2hex(random_bytes(8))));
    }
}
