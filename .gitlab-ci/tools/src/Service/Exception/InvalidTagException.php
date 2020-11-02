<?php declare(strict_types=1);

namespace Shopware\CI\Service\Exception;

class InvalidTagException extends \Exception
{
    public function __construct(string $tag)
    {
        parent::__construct('Invalid tag: ' . $tag);
    }
}
