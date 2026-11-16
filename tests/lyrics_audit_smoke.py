#!/usr/bin/env python3

import importlib.util
import os
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MODULE_PATH = os.path.join(ROOT, 'cli', 'lyrics_audit.py')

spec = importlib.util.spec_from_file_location('lyrics_audit', MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def expect(condition, message):
    if not condition:
        raise AssertionError(message)
    print(f'  ok  {message}')


def issue_types(issues):
    return {issue['type'] for issue in issues}


clean = (
    '[00:01.00] Hello world this is a real line\n'
    '[00:02.00] Another real line here\n'
    '[00:03.00] And one more line to be safe\n'
    '[00:04.00] Four lines of lyrics now'
)
issues, stats = module.detect_issues(clean)
expect(issues == [], 'clean synced lyrics produce no issue')
expect(stats['lyrics_synced'] is True, 'clean synced lyrics are detected as synced')
expect(stats['word_count'] >= 8, 'clean lyrics expose a word count')

issues, _ = module.detect_issues('Comme la reine Ã©tait trÃ¨s belle et ï¿½ï¿½ï¿½')
expect('encoding' in issue_types(issues), 'mojibake is detected')

issues, _ = module.detect_issues('<div class="lyrics">Read More</div>')
expect('junk' in issue_types(issues), 'html and boilerplate are detected as junk')

issues, _ = module.detect_issues('[00:12.40] one\n[00:12.10] two\n[00:12.10] three')
expect('timestamps' in issue_types(issues), 'non-monotonic timestamps are detected')

issues, _ = module.detect_issues('short')
expect('length' in issue_types(issues), 'very short lyrics are flagged')

issues, _ = module.detect_issues('la la ' * 8)
expect('repetition' in issue_types(issues), 'placeholder-like syllables are flagged')

issues, _ = module.detect_issues(' '.join(['word'] * 3000))
expect('length' in issue_types(issues), 'abnormally long lyrics are flagged')

issues, _ = module.detect_issues('')
expect(issue_types(issues) == {'missing'}, 'empty lyrics are reported as missing')

lrc_metadata = (
    '[offset:500]\n'
    '[length:03:45]\n'
    '[00:01.00] Hello world this is a real line\n'
    '[00:02.00] Another real line here\n'
    '[00:03.00] And one more line to be safe\n'
    '[00:04.00] Four lines of lyrics now'
)
issues, stats = module.detect_issues(lrc_metadata)
expect('timestamps' not in issue_types(issues), 'LRC metadata keys are ignored')
expect(stats['lyrics_synced'] is True, 'LRC metadata does not hide synced timestamps')

enhanced_lrc = (
    '[00:01.00] <00:01.20> Hello world this is a real line\n'
    '[00:02.00] <00:02.30> Another real line here\n'
    '[00:03.00] <00:03.40> And one more line to be safe\n'
    '[00:04.00] <00:04.50> Four lines of lyrics now'
)
issues, stats = module.detect_issues(enhanced_lrc)
expect('junk' not in issue_types(issues), 'enhanced LRC timestamps are not treated as HTML')
expect(stats['lyrics_synced'] is True, 'enhanced LRC timestamps count as synced')

issues, _ = module.detect_issues('{"lyrics": "Hello world this is a JSON payload with enough words to avoid the length rule"}')
expect('junk' in issue_types(issues), 'JSON-like payloads are flagged as junk')

with tempfile.TemporaryDirectory() as root_dir, tempfile.TemporaryDirectory() as outside_dir:
    real_file = os.path.join(root_dir, 'track.mp3')
    with open(real_file, 'w', encoding='utf-8') as handle:
        handle.write('fake')
    link_file = os.path.join(root_dir, 'link.mp3')
    try:
        os.symlink(os.path.join(outside_dir, 'outside.mp3'), link_file)
    except OSError:
        print('  skip  symlink creation is not available')
    else:
        files = module.audio_files(root_dir, root_dir, False)
        expect(files == [real_file], 'audio_files ignores symlinks')

issues, _ = module.detect_issues('line one\nline one\nline one\nline one\nline one\nline one\nline one\nline two')
expect('repetition' in issue_types(issues), 'duplicate lines are flagged as repetition')

print('Lyrics audit smoke tests passed.')
sys.exit(0)
