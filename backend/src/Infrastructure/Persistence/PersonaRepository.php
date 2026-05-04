<?php
namespace Infrastructure\Persistence;

use Domain\Agents\DecisionDynamics;
use Infrastructure\Markdown\MarkdownFileLoader;

class PersonaRepository {
    private MarkdownFileLoader $loader;
    private PersonaDecisionDynamicsRepository $personaDynamicsRepo;

    public function __construct(?PersonaDecisionDynamicsRepository $personaDynamicsRepo = null) {
        $storageDir = __DIR__ . '/../../../storage';
        $this->loader               = new MarkdownFileLoader($storageDir);
        $this->personaDynamicsRepo = $personaDynamicsRepo ?? new PersonaDecisionDynamicsRepository();
    }

    public function findAll(): array {
        return $this->loader->loadAll('personas');
    }

    public function findById(string $id): ?array {
        return $this->loader->loadById('personas', $id);
    }

    /**
     * Attaches normalized decision_dynamics to each persona (DB + optional frontmatter in $personas rows).
     *
     * @param array<int,array<string,mixed>> $personas
     * @return array<int,array<string,mixed>>
     */
    public function enrichDecisionDynamics(array $personas): array {
        foreach ($personas as &$p) {
            $id = (string)($p['id'] ?? '');
            $fm = null;
            if (isset($p['decision_dynamics']) && is_array($p['decision_dynamics'])) {
                $fm = $p['decision_dynamics'];
            }
            $p['decision_dynamics'] = $id !== ''
                ? $this->personaDynamicsRepo->resolveForPersona($id, $fm)
                : DecisionDynamics::defaults();
        }
        unset($p);
        return $personas;
    }
}
