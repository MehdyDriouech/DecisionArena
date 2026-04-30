<?php
namespace Infrastructure\Persistence;

use Infrastructure\Markdown\MarkdownFileLoader;

class PersonaRepository {
    private MarkdownFileLoader $loader;

    public function __construct() {
        $storageDir = __DIR__ . '/../../../storage';
        $this->loader = new MarkdownFileLoader($storageDir);
    }

    public function findAll(): array {
        return $this->loader->loadAll('personas');
    }

    public function findById(string $id): ?array {
        return $this->loader->loadById('personas', $id);
    }
}
