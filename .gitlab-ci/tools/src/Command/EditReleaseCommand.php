<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Shopware\CI\Service\ReleasePrepareService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class EditReleaseCommand extends ReleaseCommand
{
    public static $defaultName = 'release:edit';

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('This command can only be run interactively');

            return 1;
        }

        $deployFilesystem = $this->getDeployFilesystem($input, $io);

        $tempFilePath = tempnam('/tmp', ReleasePrepareService::SHOPWARE_XML_PATH . '.cur_');
        $tempFileHandle = fopen($tempFilePath, 'wb');
        $shopwareXml = $deployFilesystem->readStream(ReleasePrepareService::SHOPWARE_XML_PATH);
        if ($shopwareXml === false) {
            throw new \RuntimeException('Could not read Shopware xml file');
        }
        $copyResult = stream_copy_to_stream($shopwareXml, $tempFileHandle);
        if ($copyResult === false) {
            throw new \RuntimeException('Could not copy Shopware xml');
        }
        fclose($tempFileHandle);

        $tempFilePathNew = tempnam('/tmp', ReleasePrepareService::SHOPWARE_XML_PATH . '.new_');
        copy($tempFilePath, $tempFilePathNew);

        $io->writeln('Saving ' . ReleasePrepareService::SHOPWARE_XML_PATH . ' to temporary file: ' . $tempFilePathNew);

        $this->runInteractive($_SERVER['EDITOR'] . ' ' . $tempFilePathNew);

        do {
            $finished = $io->confirm('Finished editing the file?');
        } while (!$finished);

        $this->runInteractive('diff -p ' . escapeshellarg($tempFilePath) . ' ' . escapeshellarg($tempFilePathNew));

        if ($io->confirm('Do you want to deploy the change?')) {
            $tempFileHandleNew = fopen($tempFilePathNew, 'rb');
            $deployFilesystem->putStream(ReleasePrepareService::SHOPWARE_XML_PATH, $tempFileHandleNew);
            fclose($tempFileHandleNew);
            unlink($tempFilePathNew);
        }

        return 0;
    }

    protected function configure(): void
    {
        $this->setDescription('Edit shopware xml')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deploy to s3');
    }

    private function runInteractive(string $cmd): void
    {
        $descriptors = [
            ['file', '/dev/tty', 'r'],
            ['file', '/dev/tty', 'w'],
            ['file', '/dev/tty', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        while (proc_get_status($process)['running'] !== false) {
            sleep(1);
        }
    }
}
