<?php declare(strict_types=1);

namespace Shopware\CI\Service\Exception;

class TaggingException extends \Exception
{
    /**
     * @var string
     */
    private $tag;

    /**
     * @var string
     */
    private $path;

    public function __construct(string $tag, string $path, string $message)
    {
        parent::__construct('Tag "' . $tag . '", Path "' . $path . '": ' . $message);

        $this->tag = $tag;
        $this->path = $path;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
