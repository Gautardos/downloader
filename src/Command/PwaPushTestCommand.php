<?php

namespace App\Command;

use App\Service\Pwa\PushSubscriptionStore;
use App\Service\Pwa\VapidKeyService;
use App\Service\Pwa\WebPushServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:pwa:test-push',
    description: 'Send a test push notification to every stored subscription.',
)]
class PwaPushTestCommand extends Command
{
    public function __construct(
        private VapidKeyService $vapid,
        private PushSubscriptionStore $store,
        private WebPushServiceInterface $webPush
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Notification title.', 'Downloader test notification')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Notification body.', 'Push notifications are working.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'In-app URL to open on click.', '/config');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->vapid->isConfigured()) {
            $output->writeln('<error>VAPID keys are missing. Run php bin/console app:pwa:vapid.</error>');
            return Command::FAILURE;
        }

        if ($this->store->countSubscriptions(true) === 0) {
            $output->writeln('<comment>No push subscription is registered.</comment>');
            return Command::FAILURE;
        }

        $result = $this->webPush->sendToAll([
            'title' => (string) $input->getOption('title'),
            'body' => (string) $input->getOption('body'),
            'icon' => '/pwa-icons/icon-192.png',
            'url' => (string) $input->getOption('url'),
            'tag' => 'pwa-test',
        ], 60);

        $output->writeln('Sent: ' . (int) $result['sent']);
        $output->writeln('Failed: ' . (int) $result['failed']);
        $output->writeln('Removed expired: ' . (int) $result['removed']);

        return (int) $result['sent'] > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
