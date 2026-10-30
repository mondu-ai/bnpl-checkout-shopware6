<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Resources\snippet\nl_NL;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_nl_NL implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'mondu.nl-NL';
    }

    public function getPath(): string
    {
        return __DIR__ . '/flow.nl-NL.json';
    }

    public function getIso(): string
    {
        return 'nl-NL';
    }

    public function getAuthor(): string
    {
        return 'Mondu GmbH';
    }

    public function isBase(): bool
    {
        return false;
    }
}

