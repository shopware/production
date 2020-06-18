<?php


namespace Shopware\CI\Service;


class UpdateApiService
{
    /**
     * @var string
     */
    private $updateApiHost;

    public function __construct(string $updateApiHost)
    {
        $this->updateApiHost = $updateApiHost;
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
            $escapedParameters[] = $key . '=' . escapeshellarg($value);
        }
        $command = sprintf(
            'ssh shopware@%s php /var/www/shopware-update-api/bin/console %s %s',
            escapeshellarg($this->updateApiHost),
            escapeshellarg($cmd),
            implode(' ', $escapedParameters)
        );

        $returnCode = 0;

        // TODO: activate
        echo $command . PHP_EOL;
        // system($command, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to execute "' . $command . '". Return code: ' . $returnCode);
        }
    }
}
