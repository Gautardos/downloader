<?php

namespace App\Command;

use App\Service\Automation\AutomationStore;
use App\Service\Automation\EpisodeNumberingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:series-numbering',
    description: 'Detects or refreshes acquisition numbering profiles for series.',
)]
class SeriesNumberingCommand extends Command
{
    private AutomationStore $store;
    private EpisodeNumberingService $numbering;

    public function __construct(AutomationStore $store, EpisodeNumberingService $numbering)
    {
        parent::__construct();
        $this->store = $store;
        $this->numbering = $numbering;
    }

    protected function configure(): void
    {
        $this->addOption('tmdb', null, InputOption::VALUE_REQUIRED, 'Detect one series by TMDB id.');
        $this->addOption('imdb', null, InputOption::VALUE_REQUIRED, 'Detect one series by IMDb id.');
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title override for a single series.');
        $this->addOption('year', null, InputOption::VALUE_REQUIRED, 'Year override for a single series.');
        $this->addOption('refresh', null, InputOption::VALUE_NONE, 'Force re-detection even when a profile exists.');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of series to inspect.', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refresh = (bool) $input->getOption('refresh');
        $tmdbId = $input->getOption('tmdb');
        $imdbId = trim((string) $input->getOption('imdb'));
        $title = trim((string) $input->getOption('title'));
        $year = $input->getOption('year');

        if (is_numeric($tmdbId) || $imdbId !== '') {
            $identity = [
                'tmdb_id' => is_numeric($tmdbId) ? (int) $tmdbId : null,
                'imdb_id' => $imdbId,
                'title' => $title,
                'year' => is_numeric($year) ? (int) $year : null,
            ];
            if ($identity['tmdb_id'] === null && $imdbId !== '') {
                $entry = $this->store->getPdo()->query(
                    "SELECT tmdb_id, title, year FROM library_entries WHERE imdb_id = " . $this->store->getPdo()->quote($imdbId) . " AND status = 'active' ORDER BY id DESC LIMIT 1"
                )->fetch();
                if ($entry) {
                    $identity['tmdb_id'] = !empty($entry['tmdb_id']) ? (int) $entry['tmdb_id'] : null;
                    $identity['title'] = $identity['title'] !== '' ? $identity['title'] : (string) ($entry['title'] ?? '');
                    $identity['year'] = $identity['year'] ?? (!empty($entry['year']) ? (int) $entry['year'] : null);
                }
            }
            $this->report($output, $identity, $refresh);

            return Command::SUCCESS;
        }

        $limit = max(1, min(1000, (int) $input->getOption('limit', 100)));
        $stmt = $this->store->getPdo()->prepare(
            "SELECT tmdb_id, imdb_id, MAX(title) AS title, MAX(year) AS year
             FROM library_entries
             WHERE status = 'active'
               AND media_type IN ('series', 'anime')
               AND tmdb_id IS NOT NULL
             GROUP BY tmdb_id, imdb_id
             ORDER BY title
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            $output->writeln('No series with a TMDB id found in the library index.');

            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->report($output, [
                'tmdb_id' => (int) $row['tmdb_id'],
                'imdb_id' => (string) ($row['imdb_id'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'year' => !empty($row['year']) ? (int) $row['year'] : null,
            ], $refresh);
        }

        return Command::SUCCESS;
    }

    private function report(OutputInterface $output, array $identity, bool $refresh): void
    {
        $profile = $this->numbering->profileFor($identity, $refresh, true);
        if ($profile === null) {
            $output->writeln(sprintf(
                '%s (TMDB %s): no numbering profile',
                $identity['title'] ?: 'Unknown',
                $identity['tmdb_id'] ?: '?'
            ));

            return;
        }

        $segments = array_map(
            static fn (array $segment) => 'S' . $segment['season'] . '=' . $segment['episodes'],
            $profile['segments'] ?? []
        );
        $output->writeln(sprintf(
            '%s (TMDB %s): %s · %s · %s%% · %s',
            $identity['title'] ?: 'Unknown',
            $identity['tmdb_id'] ?: '?',
            (string) ($profile['status'] ?? 'unknown'),
            (string) ($profile['source'] ?? 'auto'),
            (int) round(((float) ($profile['confidence'] ?? 0)) * 100),
            $segments ? implode(' ', $segments) : ((string) ($profile['reason'] ?? 'no segments'))
        ));
    }
}
