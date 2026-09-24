<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use ZipArchive;

class GitHubUpdater
{
    private const RELEASE_URL = 'https://api.github.com/repos/drnecrotix/bggamer-discord/releases/latest';
    private const ASSET = 'bggamer-portal.zip';

    public function latest(): ?array
    {
        $response = Http::acceptJson()->withHeaders(['User-Agent' => 'BG-GAMER-Portal'])
            ->timeout(8)->get(self::RELEASE_URL);
        if ($response->status() === 404) { return null; }
        if (! $response->successful()) { throw new RuntimeException('GitHub releases are unavailable.'); }
        $release = $response->json();
        $asset = collect($release['assets'] ?? [])->firstWhere('name', self::ASSET);
        if (! $asset || ! preg_match('/^sha256:[a-f0-9]{64}$/i', $asset['digest'] ?? '')
            || ($asset['size'] ?? 0) > 20 * 1024 * 1024
            || ($asset['size'] ?? 0) < 1
            || ! preg_match('/^v?\d+\.\d+\.\d+$/', $release['tag_name'] ?? '')) {
            return ['version' => $release['tag_name'] ?? 'unknown', 'ready' => false];
        }
        $url = $asset['browser_download_url'] ?? '';
        if (! str_starts_with($url, 'https://github.com/drnecrotix/bggamer-discord/releases/download/')) {
            throw new RuntimeException('Unexpected GitHub release asset URL.');
        }
        return [
            'version' => $release['tag_name'], 'ready' => true,
            'digest' => strtolower($asset['digest']), 'url' => $url,
        ];
    }

    public function apply(array $release): int
    {
        if (! extension_loaded('zip')) { throw new RuntimeException('PHP Zip extension is required.'); }
        $response = Http::timeout(40)->get($release['url']);
        if (! $response->successful() || strlen($response->body()) > 20 * 1024 * 1024) {
            throw new RuntimeException('Release download failed or exceeds the size limit.');
        }
        if (! hash_equals($release['digest'], 'sha256:'.hash('sha256', $response->body()))) {
            throw new RuntimeException('Release checksum mismatch.');
        }
        $temp = tempnam(storage_path('app'), 'portal-update-');
        file_put_contents($temp, $response->body());
        $zip = new ZipArchive();
        $backups = [];
        $created = [];
        $backupDir = storage_path('app/update-backups/'.date('Ymd-His').'-'.bin2hex(random_bytes(4)));
        try {
            if ($zip->open($temp) !== true) { throw new RuntimeException('Invalid release ZIP.'); }
            $files = [];
            $total = 0;
            if ($zip->numFiles > 500) { throw new RuntimeException('Too many files in release.'); }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_ends_with($name, '/')) { continue; }
                if (! str_starts_with($name, 'portal/')) { throw new RuntimeException('Unexpected release path.'); }
                $relative = substr($name, 7);
                if (! preg_match('~^(app|config|resources|routes|public)/[a-zA-Z0-9_./-]+$~', $relative)
                    || str_contains($relative, '..') || str_contains($relative, '//')
                    || $relative === 'public/install.php'
                    || (($stat['external_attributes'] ?? 0) >> 16 & 0170000) === 0120000) {
                    throw new RuntimeException('Unsafe or unsupported release file.');
                }
                $total += $stat['size'];
                if ($total > 50 * 1024 * 1024) { throw new RuntimeException('Release is too large.'); }
                $files[$relative] = $name;
            }
            if (! isset($files['app/Services/Discord.php']) || ! isset($files['routes/web.php'])) {
                throw new RuntimeException('Release is missing core portal files.');
            }
            foreach ($files as $relative => $name) {
                $content = $zip->getFromName($name);
                if ($content === false) { throw new RuntimeException('Cannot extract release file.'); }
                $target = base_path($relative);
                $directory = dirname($target);
                if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
                    throw new RuntimeException('Cannot create update directory.');
                }
                for ($parent = $directory; $parent !== base_path() && dirname($parent) !== $parent; $parent = dirname($parent)) {
                    if (is_link($parent)) { throw new RuntimeException('Symlinks are not supported.'); }
                }
                if (is_link($target)) { throw new RuntimeException('Symlinks are not supported.'); }
                if (is_file($target)) {
                    $backup = $backupDir.'/'.$relative;
                    if (! is_dir(dirname($backup))) { mkdir(dirname($backup), 0700, true); }
                    if (! copy($target, $backup)) { throw new RuntimeException('Backup failed.'); }
                    $backups[$target] = $backup;
                } else { $created[] = $target; }
                $staging = $target.'.portal-tmp-'.bin2hex(random_bytes(4));
                if (file_put_contents($staging, $content, LOCK_EX) === false || ! rename($staging, $target)) {
                    @unlink($staging);
                    throw new RuntimeException('Cannot replace release file.');
                }
            }
            Artisan::call('optimize:clear');
            return count($files);
        } catch (\Throwable $exception) {
            foreach ($backups as $target => $backup) { copy($backup, $target); }
            foreach ($created as $target) { @unlink($target); }
            throw $exception;
        } finally {
            if ($zip->status === ZipArchive::ER_OK) { $zip->close(); }
            @unlink($temp);
        }
    }
}
