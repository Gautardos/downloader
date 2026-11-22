<?php

namespace App\Command;

use App\Service\Pwa\VapidKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:pwa:vapid',
    description: 'Generate or display the VAPID key pair used for PWA push notifications.',
)]
class PwaVapidCommand extends Command
{
    public function __construct(private VapidKeyService $vapid)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Regenerate the key pair even when keys already exist.')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Display the current public key without generating new keys.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');

        if ($input->getOption('show') && !$force) {
            if (!$this->vapid->isConfigured()) {
                $output->writeln('<comment>VAPID keys are not configured. Run php bin/console app:pwa:vapid.</comment>');
                return Command::SUCCESS;
            }

            $output->writeln('VAPID public key: ' . $this->vapid->getPublicKey());
            $output->writeln('Key file: ' . $this->vapid->getPath());
            $output->writeln('The private key is never displayed.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->vapid->generate($force);
        } catch (\Throwable $e) {
            $output->writeln('<error>Unable to generate VAPID keys: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!$result['created']) {
            $output->writeln('<comment>VAPID keys already exist. Use --force to regenerate them.</comment>');
        } else {
            $output->writeln('<info>VAPID keys generated.</info>');
        }

        $output->writeln('VAPID public key: ' . $result['publicKey']);
        $output->writeln('Key file: ' . $result['path'] . ' (mode 0600)');
        $output->writeln('The private key is stored server-side and never exposed.');

        return Command::SUCCESS;
    }
}
