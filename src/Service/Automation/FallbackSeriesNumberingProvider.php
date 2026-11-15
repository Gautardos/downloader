<?php

namespace App\Service\Automation;

class FallbackSeriesNumberingProvider implements SeriesNumberingProviderInterface
{
    private AiometadataSeriesNumberingProvider $aiometadata;
    private CinemetaSeriesNumberingProvider $cinemeta;
    private TvmazeSeriesNumberingProvider $tvmaze;

    public function __construct(
        AiometadataSeriesNumberingProvider $aiometadata,
        CinemetaSeriesNumberingProvider $cinemeta,
        TvmazeSeriesNumberingProvider $tvmaze
    ) {
        $this->aiometadata = $aiometadata;
        $this->cinemeta = $cinemeta;
        $this->tvmaze = $tvmaze;
    }

    public function fetch(array $identity, bool $refresh = false): array
    {
        $errors = [];
        foreach ([$this->aiometadata, $this->cinemeta, $this->tvmaze] as $provider) {
            $result = $provider->fetch($identity, $refresh);
            if (!empty($result['success']) && !empty($result['videos'])) {
                return $result;
            }
            $errors[] = [
                'provider' => $result['provider'] ?? get_class($provider),
                'reason' => $result['reason'] ?? 'no_result',
            ];
        }

        return ['success' => false, 'provider' => null, 'reason' => 'all_providers_failed', 'videos' => [], 'source_ref' => null, 'errors' => $errors];
    }
}
