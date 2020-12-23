<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class CredentialService
{
    public function getCredentials(InputInterface $input, OutputInterface $output): array
    {
        $io = new SymfonyStyle($input, $output);

        $username = $_SERVER['JIRA_API_USERNAME'] ?? null;
        if (empty($username)) {
            $question = new Question('Shopware username');
            $question->setMaxAttempts(2);
            $question->setValidator(static function (string $value): string {
                if (trim($value) === '') {
                    throw new InvalidOptionException('The username cannot be empty');
                }

                return $value;
            });

            $username = $io->askQuestion($question);
        }

        $password = $_SERVER['JIRA_API_PASSWORD'] ?? null;
        if (empty($password)) {
            $question = new Question('Shopware password');
            $question->setHidden(true);
            $question->setValidator(static function (string $value): string {
                if (trim($value) === '') {
                    throw new InvalidOptionException('The password cannot be empty');
                }

                return $value;
            });

            $password = $io->askQuestion($question);
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }
}
