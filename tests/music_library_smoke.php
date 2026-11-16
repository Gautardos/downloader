<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Service\JsonStorage;
use App\Service\MusicLibraryService;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

final class MusicLibraryTestKernel extends Kernel
{
    private string $project;

    public function __construct(string $project)
    {
        $this->project = $project;
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function getProjectDir(): string
    {
        return $this->project;
    }
}

function musicLibraryFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function musicLibraryEq(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        musicLibraryFail($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
    echo "  ok  {$message}\n";
}

function musicLibraryRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        musicLibraryRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

$base = sys_get_temp_dir() . '/music-library-smoke-' . bin2hex(random_bytes(4));
$project = $base . '/project';
$music = $base . '/music';
$library = $base . '/library';
$outside = $base . '/outside';

mkdir($project . '/var/storage', 0777, true);
mkdir($music . '/Album', 0777, true);
mkdir($library, 0777, true);
mkdir($outside, 0777, true);
file_put_contents($project . '/var/storage/config.json', json_encode([
    'music_root_path' => $music,
    'music_venv_path' => 'missing-venv',
]));
file_put_contents($music . '/track.mp3', 'fake');
file_put_contents($music . '/notes.txt', 'ignore me');
file_put_contents($music . '/Album/song.flac', 'fake');
file_put_contents($library . '/library-track.mp3', 'fake');
file_put_contents($outside . '/escape.mp3', 'fake');
@symlink($outside . '/escape.mp3', $music . '/escape.mp3');

try {
    $kernel = new MusicLibraryTestKernel($project);
    $storage = new JsonStorage($kernel);
    $service = new MusicLibraryService($storage, $kernel);

    echo "Music library paths\n";
    $root = $service->getRoot('downloads');
    musicLibraryEq(realpath($music), $root, 'downloads root resolves');
    musicLibraryEq(['downloads'], array_keys($service->getRoots()), 'only downloads is available at first');

    $traversal = $service->resolveDirectory('downloads', '../');
    musicLibraryEq('Invalid path.', $traversal['error'], 'parent traversal is rejected');

    $unsupported = $service->resolveFile('downloads', 'notes.txt');
    musicLibraryEq('Unsupported audio file.', $unsupported['error'], 'non-audio file is rejected');

    $audio = $service->resolveFile('downloads', 'track.mp3');
    musicLibraryEq(null, $audio['error'], 'audio file resolves');
    musicLibraryEq('track.mp3', $audio['relative'], 'relative audio path is kept');

    $escape = $service->resolveFile('downloads', 'escape.mp3');
    musicLibraryEq('Invalid path.', $escape['error'], 'symlink outside the root is rejected');

    $bulkLyrics = $service->updateTags('downloads', ['track.mp3', 'Album/song.flac'], ['lyrics' => 'shared']);
    musicLibraryEq(false, $bulkLyrics['success'] ?? null, 'bulk lyrics update is rejected');
    musicLibraryEq('Lyrics can only be edited for one track at a time.', $bulkLyrics['message'] ?? null, 'bulk lyrics rejection explains the rule');

    echo "Music library folder listing\n";
    $listing = $service->listFolder('downloads', '');
    musicLibraryEq(true, $listing['success'] ?? false, 'root listing succeeds');
    musicLibraryEq(1, count($listing['files'] ?? []), 'only audio files are listed');
    musicLibraryEq('track.mp3', $listing['files'][0]['name'] ?? null, 'root audio file is present');
    musicLibraryEq(1, count($listing['subfolders'] ?? []), 'subfolder is listed');
    musicLibraryEq('Album', $listing['subfolders'][0]['path'] ?? null, 'subfolder path is relative');
    musicLibraryEq(1, $listing['subfolders'][0]['track_count'] ?? null, 'subfolder track count is direct');

    $album = $service->listFolder('downloads', 'Album');
    musicLibraryEq(true, $album['success'] ?? false, 'subfolder listing succeeds');
    musicLibraryEq('Album', $album['folder']['path'] ?? null, 'subfolder path is normalized');
    musicLibraryEq(1, count($album['files'] ?? []), 'subfolder audio file is listed');

    $outsideListing = $service->listFolder('downloads', '../outside');
    musicLibraryEq('Invalid path.', $outsideListing['message'] ?? null, 'listing outside the root is rejected');

    echo "Music library root priority\n";
    file_put_contents($project . '/var/storage/config.json', json_encode([
        'music_library_path' => $library,
        'music_root_path' => $music,
        'music_venv_path' => 'missing-venv',
    ]));
    musicLibraryEq(['downloads', 'library'], array_keys($service->getRoots()), 'both roots are available');
    musicLibraryEq(realpath($library), $service->getRoot('library'), 'library target path resolves');
    $libraryListing = $service->listFolder('library', '');
    musicLibraryEq('library-track.mp3', $libraryListing['files'][0]['name'] ?? null, 'listing follows the library target path');
    $downloadListing = $service->listFolder('downloads', '');
    musicLibraryEq('track.mp3', $downloadListing['files'][0]['name'] ?? null, 'downloads root keeps its own files');

    file_put_contents($project . '/var/storage/config.json', json_encode([
        'music_library_path' => $base . '/missing-library',
        'music_root_path' => $music,
        'music_venv_path' => 'missing-venv',
    ]));
    musicLibraryEq(['downloads'], array_keys($service->getRoots()), 'library root is hidden when unavailable');
    musicLibraryEq(realpath($music), $service->getRoot('downloads'), 'download root still resolves');

    echo "Music library audit cache\n";
    $missingAudit = $service->getCachedAuditForFolder('downloads', 'Album');
    musicLibraryEq(null, $missingAudit, 'no audit is cached before a scan');
} finally {
    musicLibraryRemove($base);
}

echo "Music library smoke tests passed.\n";
