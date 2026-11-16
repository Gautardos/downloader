#!/usr/bin/env python3

import argparse
import json
import os
import re
import sys

AUDIO_EXTENSIONS = {'.mp3', '.flac', '.m4a', '.opus', '.ogg', '.wav'}

LRC_TIMESTAMP_RE = re.compile(r'\[\s*(\d{1,3}):(\d{2})(?:[.:](\d{1,3}))?\s*\]')
LRC_ENHANCED_RE = re.compile(r'<\s*(\d{1,3}):(\d{2})(?:[.:](\d{1,3}))?\s*>')
LRC_METADATA_RE = re.compile(r'^\s*\[[A-Za-z][A-Za-z0-9_-]*:[^\]]*\]\s*$')
SECTION_RE = re.compile(r'^\s*\[[^\]]+\]\s*$')
HTML_RE = re.compile(r'<[^>]{1,200}>')
ENTITY_RE = re.compile(r'&(?:[a-zA-Z]+|#\d+|#x[0-9a-fA-F]+);')
URL_RE = re.compile(r'(?:https?://|www\.)\S+', re.IGNORECASE)
MOJIBAKE_RE = re.compile(r'Ã[\x80-\xBF]|Â[\x80-\xBF]|â€|ï¿½|ðŸ|Ã©|Ã¨|Ã |Ã§|Ã»|Ã®|Ã´|Ã¢')
PLACEHOLDER_RE = re.compile(r'\b(la\s+la|na\s+na|oh\s+oh|yeah\s+yeah|hey\s+hey)\b', re.IGNORECASE)
WORD_RE = re.compile(r"[A-Za-zÀ-ÖØ-öø-ÿ0-9]+(?:['’][A-Za-zÀ-ÖØ-öø-ÿ0-9]+)?")
JSON_KEY_RE = re.compile(r'"\s*(?:lyrics|text|content|title|artist|track|album)\s*"\s*:', re.IGNORECASE)
JSON_SHAPE_RE = re.compile(r'"(?:[^"\\]|\\.)*"\s*:', re.IGNORECASE)

BOILERPLATE = (
    'read more',
    'embed',
    'share',
    'lyrics powered',
    'powered by',
    'submit lyrics',
    'you might also like',
    'contributors',
    'translation',
    'paroles',
    'letra',
    'genius',
    'musixmatch',
    'songtexte',
    'letras',
    'textes',
)

SEVERITY_RANK = {'ok': 0, 'info': 1, 'warning': 2, 'error': 3}


def normalize_text(value):
    return ' '.join(str(value or '').replace('\u00a0', ' ').split())


def excerpt(text, start, length=110):
    left = max(0, start - 40)
    right = min(len(text), start + length)
    return normalize_text(text[left:right])


def strip_timestamps(value):
    value = LRC_TIMESTAMP_RE.sub(' ', value)
    return LRC_ENHANCED_RE.sub(' ', value)


def content_lines(lyrics):
    lines = []
    for raw in str(lyrics or '').splitlines():
        stripped = raw.strip()
        if not stripped or SECTION_RE.match(stripped) or LRC_METADATA_RE.match(stripped):
            continue
        without_timestamps = strip_timestamps(raw).strip()
        if not without_timestamps or SECTION_RE.match(without_timestamps) or LRC_METADATA_RE.match(without_timestamps):
            continue
        lines.append(without_timestamps)
    return lines


def timestamp_matches(line):
    matches = list(LRC_TIMESTAMP_RE.finditer(line)) + list(LRC_ENHANCED_RE.finditer(line))
    return sorted(matches, key=lambda match: match.start())


def parse_timestamps(lyrics):
    stamps = []
    invalid = []
    for raw in str(lyrics or '').splitlines():
        if LRC_METADATA_RE.match(raw.strip()):
            continue

        matches = timestamp_matches(raw)
        if matches:
            for match in matches:
                minutes = int(match.group(1))
                seconds = int(match.group(2))
                fraction = match.group(3) or ''
                if seconds >= 60:
                    invalid.append(match.group(0))
                    continue
                value = minutes * 60 + seconds
                if fraction:
                    value += int(fraction) / (10 ** len(fraction))
                stamps.append(value)
            continue

        stripped = raw.strip()
        if stripped.startswith('[') and ']' in stripped:
            inner = stripped[1:stripped.index(']')]
            if re.search(r'\d', inner) and ':' in inner:
                invalid.append(stripped[:40])

    return stamps, invalid


def detect_issues(lyrics):
    text = str(lyrics or '').strip()
    if not text or text.lower() in {'none', '[]', '[none]'}:
        issues = [{
            'type': 'missing',
            'severity': 'info',
            'message': 'No lyrics stored',
            'excerpt': '',
        }]
        return issues, {
            'has_lyrics': False,
            'lyrics_synced': False,
            'line_count': 0,
            'word_count': 0,
            'char_count': 0,
        }

    issues = []
    stamps, invalid_stamps = parse_timestamps(text)

    mojibake = MOJIBAKE_RE.findall(text)
    replacements = text.count('\ufffd')
    control_chars = sum(1 for char in text if ord(char) < 32 and char not in '\n\r\t')
    if mojibake or replacements or control_chars:
        marker_count = len(mojibake) + replacements + control_chars
        match = MOJIBAKE_RE.search(text)
        start = match.start() if match else max(0, text.find('\ufffd'))
        issues.append({
            'type': 'encoding',
            'severity': 'error',
            'message': f'Possible mojibake or broken encoding ({marker_count} marker(s))',
            'excerpt': excerpt(text, start),
        })

    if invalid_stamps:
        issues.append({
            'type': 'timestamps',
            'severity': 'error',
            'message': f'Invalid LRC timestamp(s): {", ".join(invalid_stamps[:3])}',
            'excerpt': ', '.join(invalid_stamps[:3]),
        })
    elif len(stamps) >= 2 and any(right < left for left, right in zip(stamps, stamps[1:])):
        issues.append({
            'type': 'timestamps',
            'severity': 'error',
            'message': 'LRC timestamps are not in ascending order',
            'excerpt': '',
        })

    junk_text = LRC_ENHANCED_RE.sub(' ', text)

    html_match = HTML_RE.search(junk_text)
    if html_match:
        issues.append({
            'type': 'junk',
            'severity': 'error',
            'message': 'HTML markup found in lyrics',
            'excerpt': excerpt(junk_text, html_match.start()),
        })

    entity_match = ENTITY_RE.search(junk_text)
    if entity_match:
        issues.append({
            'type': 'junk',
            'severity': 'warning',
            'message': 'HTML entities found in lyrics',
            'excerpt': excerpt(junk_text, entity_match.start()),
        })

    url_match = URL_RE.search(junk_text)
    if url_match:
        issues.append({
            'type': 'junk',
            'severity': 'warning',
            'message': 'URL found in lyrics',
            'excerpt': excerpt(junk_text, url_match.start()),
        })

    stripped_for_json = junk_text.strip()
    looks_like_json = bool(JSON_KEY_RE.search(junk_text))
    if not looks_like_json and stripped_for_json[:1] in {'{', '['}:
        looks_like_json = bool(JSON_SHAPE_RE.search(stripped_for_json[:4000]))
    if looks_like_json:
        issues.append({
            'type': 'junk',
            'severity': 'warning',
            'message': 'JSON-like payload found in lyrics',
            'excerpt': excerpt(junk_text, 0),
        })

    lowered = text.lower()
    boilerplate = [phrase for phrase in BOILERPLATE if phrase in lowered]
    if boilerplate:
        phrase = boilerplate[0]
        start = lowered.find(phrase)
        issues.append({
            'type': 'junk',
            'severity': 'warning',
            'message': f'Scraper boilerplate found: "{phrase}"',
            'excerpt': excerpt(text, start),
        })

    lines = content_lines(text)
    normalized_lines = []
    for line in lines:
        normalized = re.sub(r'[^\w\s]', '', line.lower())
        normalized = ' '.join(normalized.split())
        if normalized:
            normalized_lines.append(normalized)

    if len(normalized_lines) >= 8:
        unique_lines = len(set(normalized_lines))
        duplicate_ratio = 1 - (unique_lines / len(normalized_lines))
        if duplicate_ratio >= 0.5:
            issues.append({
                'type': 'repetition',
                'severity': 'warning',
                'message': f'High line repetition ({round(duplicate_ratio * 100)}% duplicate lines)',
                'excerpt': normalized_lines[0][:110],
            })

    placeholder_hits = PLACEHOLDER_RE.findall(text)
    if len(placeholder_hits) >= 6:
        issues.append({
            'type': 'repetition',
            'severity': 'warning',
            'message': 'Placeholder-like repeated syllables found',
            'excerpt': ' '.join(placeholder_hits[:6]),
        })

    if 'lorem ipsum' in lowered:
        issues.append({
            'type': 'repetition',
            'severity': 'warning',
            'message': 'Lorem ipsum placeholder text found',
            'excerpt': excerpt(text, lowered.find('lorem ipsum')),
        })

    plain_text = '\n'.join(lines)
    words = WORD_RE.findall(plain_text)
    word_count = len(words)
    line_count = len(lines)
    char_count = len(plain_text)

    if word_count < 8 or (line_count <= 1 and word_count < 20):
        issues.append({
            'type': 'length',
            'severity': 'warning',
            'message': f'Lyrics are very short ({word_count} word(s), {line_count} line(s))',
            'excerpt': excerpt(text, 0),
        })
    elif word_count > 2500 or line_count > 250 or char_count > 20000:
        issues.append({
            'type': 'length',
            'severity': 'warning',
            'message': f'Lyrics look abnormally long ({word_count} word(s), {line_count} line(s))',
            'excerpt': excerpt(text, 0),
        })

    return issues, {
        'has_lyrics': True,
        'lyrics_synced': bool(stamps),
        'line_count': line_count,
        'word_count': word_count,
        'char_count': char_count,
    }


def overall_severity(issues):
    severity = 'ok'
    for issue in issues:
        if SEVERITY_RANK.get(issue.get('severity'), 1) > SEVERITY_RANK.get(severity, 0):
            severity = issue.get('severity', 'info')
    return severity


def audio_files(root, scope, recursive):
    root_real = os.path.realpath(root)
    root_prefix = root_real.rstrip(os.sep) + os.sep

    def allowed(path):
        if os.path.islink(path):
            return False
        real = os.path.realpath(path)
        return real == root_real or real.startswith(root_prefix)

    paths = []
    if recursive:
        for current, dirnames, filenames in os.walk(scope):
            dirnames[:] = sorted(
                name for name in dirnames
                if not name.startswith('.') and not os.path.islink(os.path.join(current, name))
            )
            for name in sorted(filenames):
                path = os.path.join(current, name)
                if name.startswith('.') or os.path.splitext(name)[1].lower() not in AUDIO_EXTENSIONS or not allowed(path):
                    continue
                paths.append(path)
    else:
        for name in sorted(os.listdir(scope)):
            if name.startswith('.'):
                continue
            path = os.path.join(scope, name)
            if os.path.isfile(path) and os.path.splitext(name)[1].lower() in AUDIO_EXTENSIONS and allowed(path):
                paths.append(path)
    return paths


def analyze_file(root, path):
    import music_tag

    relative = os.path.relpath(path, root).replace(os.sep, '/')
    title = ''
    artist = ''
    album = ''
    lyrics = ''

    try:
        audio = music_tag.load_file(path)
        title = str(audio['title'] or '')
        artist = str(audio['artist'] or '')
        album = str(audio['album'] or '')
        lyrics = str(audio['lyrics'] or '')
        issues, stats = detect_issues(lyrics)
    except Exception as error:
        issues = [{
            'type': 'read_error',
            'severity': 'error',
            'message': f'Could not read file tags: {str(error)[:180]}',
            'excerpt': '',
        }]
        stats = {
            'has_lyrics': False,
            'lyrics_synced': False,
            'line_count': 0,
            'word_count': 0,
            'char_count': 0,
        }

    return {
        'path': relative,
        'filename': os.path.basename(path),
        'title': title,
        'artist': artist,
        'album': album,
        'has_lyrics': stats['has_lyrics'],
        'lyrics_synced': stats['lyrics_synced'],
        'line_count': stats['line_count'],
        'word_count': stats['word_count'],
        'char_count': stats['char_count'],
        'issues': issues,
        'severity': overall_severity(issues),
    }


def summarize(files):
    counts = {
        'tracks': len(files),
        'ok': 0,
        'warning': 0,
        'error': 0,
        'missing': 0,
        'flagged': 0,
        'issue_count': 0,
        'by_type': {},
    }
    for entry in files:
        severity = entry.get('severity', 'ok')
        if severity in counts:
            counts[severity] += 1
        issues = entry.get('issues') or []
        if any(issue.get('type') == 'missing' for issue in issues):
            counts['missing'] += 1
        if severity in {'warning', 'error'}:
            counts['flagged'] += 1
        types = {issue.get('type') for issue in issues if issue.get('type') != 'missing'}
        counts['issue_count'] += sum(1 for issue in issues if issue.get('type') != 'missing')
        for issue_type in types:
            counts['by_type'][issue_type] = counts['by_type'].get(issue_type, 0) + 1

    return counts


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True)
    parser.add_argument('--path', required=True)
    parser.add_argument('--recursive', action='store_true')
    args = parser.parse_args()

    root = os.path.realpath(args.root)
    scope = os.path.realpath(args.path)
    root_prefix = root.rstrip(os.sep) + os.sep

    if not os.path.isdir(root):
        print(json.dumps({'success': False, 'message': 'Music root is not available.'}))
        return 1
    if scope != root and not scope.startswith(root_prefix):
        print(json.dumps({'success': False, 'message': 'Path is outside the music root.'}))
        return 1
    if not os.path.isdir(scope):
        print(json.dumps({'success': False, 'message': 'Folder not found.'}))
        return 1

    try:
        paths = audio_files(root, scope, args.recursive)
    except Exception as error:
        print(json.dumps({'success': False, 'message': str(error)}))
        return 1

    files = [analyze_file(root, path) for path in paths]
    files.sort(key=lambda entry: entry['path'])

    print(json.dumps({
        'success': True,
        'scope': os.path.relpath(scope, root).replace(os.sep, '/') if scope != root else '',
        'recursive': bool(args.recursive),
        'counts': summarize(files),
        'files': files,
    }))
    return 0


if __name__ == '__main__':
    sys.exit(main())
