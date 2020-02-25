<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\FetchMode;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Production\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemGenerateJwtSecretCommand extends Command
{
    static public $defaultName = 'system:generate-jwt-secret';
    /**
     * @var string
     */
    private $projectDir;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this->addOption('private-key-path', null, InputOption::VALUE_OPTIONAL, 'JWT public key path')
            ->addOption('public-key-path', null, InputOption::VALUE_OPTIONAL, 'JWT public key path')
            ->addOption('jwt-passphrase', null, InputOption::VALUE_OPTIONAL, 'JWT private key passphrase', 'shopware')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force recreation')
        ;
    }


    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!extension_loaded('openssl')) {
            $io->error('extension openssl is required');

            return 1;
        }

        $passphrase = $input->getOption('jwt-passphrase');
        $privateKeyPath = $input->getOption('private-key-path') ?? $this->projectDir . '/config/jwt/private.pem';
        $publicKeyPath = $input->getOption('public-key-path');

        if (!$publicKeyPath && !$input->getOption('private-key-path')) {
            $publicKeyPath = $this->projectDir . '/config/jwt/public.pem';
        }

        $force = $input->getOption('force');

        if (file_exists($privateKeyPath) && !$force) {
            $io->error(sprintf('Cannot create private key %s, it already exists.', $privateKeyPath));

            return 1;
        }

        if (file_exists($publicKeyPath) && !$force) {
            $io->error(sprintf('Cannot create public key %s, it already exists.', $publicKeyPath));

            return 1;
        }

        $key = openssl_pkey_new([
            'digest_alg' => 'aes256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'encrypt_key' => $passphrase,
            'encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC
        ]);

        // export private key
        openssl_pkey_export_to_file($key, $privateKeyPath, $passphrase);
        chmod($privateKeyPath, 0660);

        if ($publicKeyPath) {
            // export public key
            $keyData = openssl_pkey_get_details($key);
            file_put_contents($publicKeyPath, $keyData['key']);
            chmod($publicKeyPath, 0660);
        }

        return 0;
    }
}
