<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use Symfony\Component\Console\Output\OutputInterface;

class UpdateApiService
{
    /**
     * @var string
     */
    private $updateApiHost;

    /**
     * @var OutputInterface
     */
    private $stdout;

    public function __construct(string $updateApiHost, OutputInterface $stdout)
    {
        $this->updateApiHost = $updateApiHost;
        $this->stdout = $stdout;
    }

    public function insertReleaseData(array $parameters): void
    {
        $this->system('insert:release:data', $parameters);
    }

    public function updateReleaseNotes(array $parameters): void
    {
        $this->system('update:release:notes', $parameters);
    }

    public function publishRelease(array $parameters): void
    {
        $this->system('publish:release', $parameters);
    }

    private function system(string $cmd, array $parameters): void
    {
        $escapedParameters = [];
        foreach ($parameters as $key => $value) {
            $escapedParameters[] = $key . '=' . escapeshellarg((string) $value);
        }
        $command = sprintf(
            'ssh shopware@%s /var/www/shopware-update-api/bin/console %s %s',
            escapeshellarg($this->updateApiHost),
            escapeshellarg($cmd),
            implode(' ', $escapedParameters)
        );

        $this->stdout->writeln('Run locally: ' . $command);
        // TODO: activate
//        $returnCode = 0;
//        system($command, $returnCode);

//        if ($returnCode !== 0) {
//            throw new \RuntimeException('Failed to execute "' . $command . '". Return code: ' . $returnCode);
//        }
    }
}
