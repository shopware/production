<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use TitasGailius\Terminal\Builder;

class ProcessBuilder extends Builder
{
    /**
     * @var string|null
     */
    private static $sshKeyPath;

    /**
     * Bind command data.
     *
     * @param mixed $key
     * @param mixed $value
     *
     * @return $this
     */
    public function with($key, $value = null)
    {
        $this->with = array_merge($this->with, \is_array($key) ? $key : [$key => $value]);

        return $this;
    }

    public static function loadSshKey(string $path): void
    {
        self::$sshKeyPath = $path;
    }

    protected function prepareCommand($command)
    {
        if (self::$sshKeyPath === null) {
            return parent::prepareCommand($command);
        }

        $this->with('ProcessBuilder_sshKeyPath', self::$sshKeyPath);
        $command = 'eval $(ssh-agent -s) >/dev/null && chmod 600 {{ $ProcessBuilder_sshKeyPath }} >/dev/null && ssh-add {{ $ProcessBuilder_sshKeyPath }}>/dev/null && ' . $command;

        return parent::prepareCommand($command);
    }
}
