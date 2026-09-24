<?php

declare(strict_types=1);

function bgPortalInstallerCanSelfDelete(string $installer): bool
{
    return is_file($installer) && is_writable(dirname($installer));
}

function bgPortalDeleteInstaller(string $installer): bool
{
    if (! bgPortalInstallerCanSelfDelete($installer)) {
        return false;
    }

    if (! @unlink($installer)) {
        return false;
    }

    clearstatcache(true, $installer);

    return ! file_exists($installer);
}
