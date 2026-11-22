<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Music\MusicDownloadCommandBuilder;
use App\Service\Music\MusicTrackVerifier;

$passes = 0;
$failures = 0;

function check(bool $condition, string $label, mixed $actual = null): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "  ok  $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label" . ($actual !== null ? ' (actual: ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ')' : '') . "\n";
}

function eq(mixed $expected, mixed $actual, string $label): void
{
    check($expected === $actual, $label . ' => ' . json_encode($expected, JSON_UNESCAPED_UNICODE), $actual);
}

echo "MusicDownloadCommandBuilder\n";

$builder = new MusicDownloadCommandBuilder();

$fullConfig = [
    'music_output' => '{artist}/{album}/{song_name}.{ext}',
    'music_format' => 'flac',
    'music_root_path' => '/music',
    'music_creds' => '/creds.json',
    'music_archive' => '/archive.txt',
    'music_quality' => 'high',
    'music_retries' => 5,
    'music_skip_existing' => true,
    'music_lyrics' => false,
    'music_progress' => true,
    'music_print_downloads' => false,
    'music_progress_info' => true,
    'spotify_client_id' => 'client-id-value',
    'spotify_client_secret' => 'client-secret-value',
];

$expected = [
    '--output' => '{artist}/{album}/{song_name}.{ext}',
    '--download-format' => 'flac',
    '--root-path' => '/music',
    '--credentials-location' => '/creds.json',
    '--song-archive' => '/archive.txt',
    '--download-quality' => 'high',
    '--retry-attempts' => 5,
    '--skip-previously-downloaded' => 'True',
    '--download-lyrics' => 'False',
    '--print-download-progress' => 'True',
    '--print-downloads' => 'False',
    '--print-progress-info' => 'True',
    '--client-id' => 'client-id-value',
    '--client-secret' => 'client-secret-value',
];

eq($expected, $builder->build($fullConfig), 'all Zotify flags are mapped with exact values');
eq(array_keys($expected), array_keys($builder->build($fullConfig)), 'flag order is stable');

$defaults = $builder->build(['music_quality' => 'ultra']);
eq('auto', $defaults['--download-quality'], 'invalid quality falls back to auto');
eq(3, $defaults['--retry-attempts'], 'retry attempts default to 3');
eq('{artist} - {album} - {song_name}.{ext}', $defaults['--output'], 'output template default');
eq('mp3', $defaults['--download-format'], 'download format default');
eq('', $defaults['--root-path'], 'root path default');
eq('', $defaults['--credentials-location'], 'credentials location default');
eq('', $defaults['--song-archive'], 'song archive default');
eq('False', $defaults['--skip-previously-downloaded'], 'skip existing default false');
eq('True', $defaults['--download-lyrics'], 'lyrics default true');
eq('False', $defaults['--print-download-progress'], 'progress default false');
eq('True', $defaults['--print-downloads'], 'print downloads default true');
eq('False', $defaults['--print-progress-info'], 'progress info default false');
eq('', $defaults['--client-id'], 'client id default');
eq('', $defaults['--client-secret'], 'client secret default');

foreach (['auto', 'normal', 'high', 'very_high'] as $quality) {
    eq($quality, $builder->build(['music_quality' => $quality])['--download-quality'], "quality $quality is preserved");
}

$truthy = $builder->build([
    'music_skip_existing' => 1,
    'music_lyrics' => '1',
    'music_progress' => true,
    'music_print_downloads' => 1,
    'music_progress_info' => 'yes',
]);
eq('True', $truthy['--skip-previously-downloaded'], 'truthy skip existing is True');
eq('True', $truthy['--download-lyrics'], 'truthy lyrics is True');
eq('True', $truthy['--print-download-progress'], 'truthy progress is True');
eq('True', $truthy['--print-downloads'], 'truthy print downloads is True');
eq('True', $truthy['--print-progress-info'], 'truthy progress info is True');

$falsy = $builder->build([
    'music_skip_existing' => 0,
    'music_lyrics' => false,
    'music_progress' => 0,
    'music_print_downloads' => false,
    'music_progress_info' => null,
]);
eq('False', $falsy['--skip-previously-downloaded'], 'falsy skip existing is False');
eq('False', $falsy['--download-lyrics'], 'falsy lyrics is False');
eq('False', $falsy['--print-download-progress'], 'falsy progress is False');
eq('False', $falsy['--print-downloads'], 'falsy print downloads is False');
eq('False', $falsy['--print-progress-info'], 'falsy progress info is False');

echo "MusicTrackVerifier\n";

$verifier = new MusicTrackVerifier();

$expectedTracks = [
    ['artist' => 'Artist One', 'album' => 'Album A', 'song_name' => 'First Song'],
    ['artist' => 'Artist Two', 'album' => 'Album A', 'song_name' => 'Second Song'],
];
$verifiedTracks = [
    ['artist' => 'Artist One', 'album' => 'Album A', 'song_name' => 'First Song', 'full_path' => '/a.mp3'],
    ['artist' => 'Artist Two', 'album' => 'Album A', 'song_name' => 'Second Song', 'full_path' => '/b.mp3'],
];

$all = $verifier->verify($expectedTracks, $verifiedTracks);
eq('success', $all['status'], 'all tracks found => success');
eq(2, $all['validatedCount'], 'all tracks found => validated count');
eq([], $all['missing'], 'all tracks found => no missing tracks');
eq('/a.mp3', $all['matched'][0]['full_path'] ?? null, 'matched entries keep verified metadata');

$partial = $verifier->verify($expectedTracks, [$verifiedTracks[0]]);
eq('warning', $partial['status'], 'partial match => warning');
eq(1, $partial['validatedCount'], 'partial match => validated count');
eq([$expectedTracks[1]], $partial['missing'], 'partial match => missing expected track');

$none = $verifier->verify($expectedTracks, []);
eq('error', $none['status'], 'no match => error');
eq(0, $none['validatedCount'], 'no match => validated count');
eq($expectedTracks, $none['missing'], 'no match => every expected track missing');

$empty = $verifier->verify([], $verifiedTracks);
eq('error', $empty['status'], 'empty expected tracks => error');
eq(0, $empty['validatedCount'], 'empty expected tracks => zero validated');
eq([], $empty['missing'], 'empty expected tracks => no missing tracks');

$normalized = $verifier->verify(
    [['artist' => 'A / B', 'album' => 'The  Album', 'song_name' => 'My Song / Reprise']],
    [['artist' => 'a, b', 'album' => 'the album', 'song_name' => 'my song, reprise']]
);
eq('success', $normalized['status'], 'artist/album/title normalization matches');
eq('a, b', $normalized['matched'][0]['artist'] ?? null, 'normalization returns the verified track');

$semicolon = $verifier->verify(
    [['artist' => 'X; Y', 'album' => 'Album', 'song_name' => 'Title']],
    [['artist' => 'X, Y', 'album' => 'Album', 'song_name' => 'Title']]
);
eq('success', $semicolon['status'], 'semicolon artist separators are normalized');

$albumDifferent = $verifier->verify(
    [['artist' => 'Artist', 'album' => 'Expected Album', 'song_name' => 'Song']],
    [['artist' => 'Artist', 'album' => 'Other Album', 'song_name' => 'Song']]
);
eq('error', $albumDifferent['status'], 'different album does not match');
eq(1, count($albumDifferent['missing']), 'different album counts the track as missing');

$titleDifferent = $verifier->verify(
    [['artist' => 'Artist', 'album' => 'Album', 'song_name' => 'Song A']],
    [['artist' => 'Artist', 'album' => 'Album', 'song_name' => 'Song B']]
);
eq('error', $titleDifferent['status'], 'different title does not match');

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
