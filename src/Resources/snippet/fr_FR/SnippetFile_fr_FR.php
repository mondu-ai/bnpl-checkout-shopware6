<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Resources\snippet\fr_FR;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_fr_FR implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'mondu.fr-FR';
    }

    public function getPath(): string
    {
        return __DIR__ . '/flow.fr-FR.json';
    }

    public function getIso(): string
    {
        return 'fr-FR';
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

