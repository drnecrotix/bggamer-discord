<?php

namespace Tests\Feature;

use App\Services\GitHubUpdater;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class GitHubUpdaterTest extends TestCase
{
    public function test_release_without_digest_is_not_installable(): void
    {
        Http::fake(['api.github.com/*' => Http::response([
            'tag_name' => 'v1.0.0',
            'assets' => [[
                'name' => 'bggamer-portal.zip', 'size' => 100,
                'browser_download_url' => 'https://github.com/drnecrotix/bggamer-discord/releases/download/v1.0.0/bggamer-portal.zip',
            ]],
        ])]);
        $release = app(GitHubUpdater::class)->latest();
        $this->assertFalse($release['ready']);
    }

    public function test_zip_path_traversal_is_rejected_before_any_write(): void
    {
        if (! extension_loaded('zip')) { $this->markTestSkipped('Zip extension unavailable.'); }
        $path = tempnam(sys_get_temp_dir(), 'ziptest');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('portal/../outside.php', 'bad');
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);
        Http::fake(['github.com/*' => Http::response($body, 200)]);
        $release = [
            'url' => 'https://github.com/drnecrotix/bggamer-discord/releases/download/v1.0.0/bggamer-portal.zip',
            'digest' => 'sha256:'.hash('sha256', $body),
        ];
        $this->expectException(RuntimeException::class);
        app(GitHubUpdater::class)->apply($release);
    }
}
