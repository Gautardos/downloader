<?php

namespace App\Command;

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:library-reconcile-missing',
    description: 'Marks active library entries as missing when their files no longer exist.',
)]
class LibraryReconcileMissingCommand extends Command
{
    private AutomationStore $store;
    private AutomationSettings $settings;

    public function __construct(AutomationStore $store, AutomationSettings $settings)
    {
        parent::__construct();
        $this->store = $store;
        $this->settings = $settings;
    }

    protected function configure(): void
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Root key(s) to reconcile, comma separated (movies, series, anime).');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Reconcile every configured root. Required when --root is omitted.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List missing files without updating the index.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootOption = trim((string) $input->getOption('root'));
        if ($rootOption === '' && !$input->getOption('all')) {
            $output->writeln('<error>Specify --root=anime (or another root) or --all.</error>');

            return Command::FAILURE;
        }

        $rootKeys = $rootOption !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $rootOption))))
            : array_keys($this->settings->getRoots());
        $entries = $this->store->getActiveLibraryEntriesForRoots($rootKeys);
        $missing = [];
        foreach ($entries as $entry) {
            $path = (string) ($entry['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                $missing[] = $entry;
            }
        }

        if ($missing === []) {
            $output->writeln('No missing file found in the selected roots.');

            return Command::SUCCESS;
        }

        $output->writeln('Missing files: ' . count($missing));
        foreach (array_slice($missing, 0, 20) as $entry) {
            $output->writeln(sprintf(
                ' - [%s] %s S%sE%s %s',
                (string) ($entry['root_key'] ?? ''),
                (string) ($entry['title'] ?? 'Unknown'),
                $entry['season'] !== null ? $entry['season'] : '?',
                $entry['episode'] !== null ? $entry['episode'] : '?',
                (string) ($entry['path'] ?? '')
            ));
        }
        if (count($missing) > 20) {
            $output->writeln(' - … ' . (count($missing) - 20) . ' more');
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('Dry-run: no index change applied.');

            return Command::SUCCESS;
        }

        $marked = $this->store->markLibraryEntriesMissing(array_map(
            static fn (array $entry): int => (int) ($entry['id'] ?? 0),
            $missing
        ));
        $output->writeln('Marked as missing: ' . $marked);

        return Command::SUCCESS;
    }
}
