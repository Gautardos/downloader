<?php

namespace App\Service;

class SafeUrlGuard
{
    private const BLOCKED_PORTS = [22, 23, 25, 53, 110, 143, 3306, 5432, 6379, 11211, 9200, 9300, 2375, 2376, 6443, 27017];

    private HostResolverInterface $resolver;

    public function __construct(?HostResolverInterface $resolver = null)
    {
        $this->resolver = $resolver ?? new DnsHostResolver();
    }

    public function validate(string $url): array
    {
        try {
            $parts = parse_url(trim($url));
        } catch (\Throwable $e) {
            return $this->reject('URL cannot be parsed.');
        }

        if (!is_array($parts)) {
            return $this->reject('URL cannot be parsed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->reject('Only HTTP and HTTPS URLs are allowed.');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return $this->reject('Credentials in URLs are not allowed.');
        }

        $host = rtrim(strtolower(trim((string) ($parts['host'] ?? ''), '[]')), '.');
        if ($host === '') {
            return $this->reject('URL host is missing.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return $this->reject('Local hosts are not allowed.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return $this->reject('URL port is invalid.');
        }
        if ($port !== null && in_array($port, self::BLOCKED_PORTS, true)) {
            return $this->reject('URL port is not allowed.');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $ips = $this->resolver->resolve($host);
            if ($ips === []) {
                return $this->reject('URL host could not be resolved.');
            }
        }

        foreach (array_unique($ips) as $ip) {
            if (!$this->isPublicIp((string) $ip)) {
                return $this->reject('URL host resolves to a non-public address.');
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function validateHttps(string $url): array
    {
        $result = $this->validate($url);
        if (empty($result['allowed'])) {
            return $result;
        }

        try {
            $parts = parse_url(trim($url));
        } catch (\Throwable $e) {
            return $this->reject('URL cannot be parsed.');
        }

        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return $this->reject('Only HTTPS URLs are allowed.');
        }

        return $result;
    }

    public function validateRedirect(string $url, int $redirectCount, array $visited = []): array
    {
        if ($redirectCount > 5) {
            return $this->reject('Too many redirects.');
        }

        if (in_array($url, $visited, true)) {
            return $this->reject('Redirect loop detected.');
        }

        return $this->validate($url);
    }

    private function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $long = unpack('N', $packed)[1];
            $ranges = [
                [0x00000000, 0x00FFFFFF],
                [0x0A000000, 0x0AFFFFFF],
                [0x64400000, 0x647FFFFF],
                [0x7F000000, 0x7FFFFFFF],
                [0xA9FE0000, 0xA9FEFFFF],
                [0xAC100000, 0xAC1FFFFF],
                [0xC0A80000, 0xC0A8FFFF],
                [0xC0000000, 0xC00000FF],
                [0xC0000200, 0xC00002FF],
                [0xC6120000, 0xC613FFFF],
                [0xC6336400, 0xC63364FF],
                [0xCB007100, 0xCB0071FF],
                [0xE0000000, 0xEFFFFFFF],
                [0xF0000000, 0xFFFFFFFF],
            ];
            foreach ($ranges as [$start, $end]) {
                if ($long >= $start && $long <= $end) {
                    return false;
                }
            }

            return true;
        }

        $mappedPrefix = substr((string) inet_pton('::ffff:0:0'), 0, 12);
        if (str_starts_with($packed, $mappedPrefix)) {
            return $this->isPublicIp((string) inet_ntop(substr($packed, 12, 4)));
        }

        if ($packed === inet_pton('::') || $packed === inet_pton('::1')) {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);
        if (($first & 0xFE) === 0xFC) {
            return false;
        }
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return false;
        }
        if ($first === 0xFF) {
            return false;
        }
        if (substr($packed, 0, 4) === substr((string) inet_pton('2001:db8::'), 0, 4)) {
            return false;
        }

        return true;
    }

    private function reject(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
