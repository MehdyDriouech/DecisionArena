<?php

declare(strict_types=1);

namespace Domain\Prompts;

/**
 * Domain service for reading and persisting prompt policies.
 *
 * Only the four whitelisted policy files are accessible.
 * No arbitrary file access is permitted.
 */
class PromptPolicyService
{
    private const ALLOWED = [
        'round-policy' => [
            'filename'    => 'round-policy.md',
            'titleKey'    => 'admin.promptPolicies.roundPolicy',
            'title'       => 'Round Policy',
            'descriptionKey' => 'admin.promptPolicies.roundPolicyDesc',
            'description' => 'Rules applied during structured debate rounds.',
        ],
        'confrontation-policy' => [
            'filename'    => 'confrontation-policy.md',
            'titleKey'    => 'admin.promptPolicies.confrontationPolicy',
            'title'       => 'Confrontation Policy',
            'descriptionKey' => 'admin.promptPolicies.confrontationPolicyDesc',
            'description' => 'Rules governing the confrontation mode phases.',
        ],
        'social-dynamics-policy' => [
            'filename'    => 'social-dynamics-policy.md',
            'titleKey'    => 'admin.promptPolicies.socialDynamicsPolicy',
            'title'       => 'Social Dynamics Policy',
            'descriptionKey' => 'admin.promptPolicies.socialDynamicsPolicyDesc',
            'description' => 'Rules for agent relationships, alliances and conflict.',
        ],
        'devil-advocate' => [
            'filename'    => 'devil_advocate.md',
            'titleKey'    => 'admin.promptPolicies.devilAdvocate',
            'title'       => "Devil's Advocate",
            'descriptionKey' => 'admin.promptPolicies.devilAdvocateDesc',
            'description' => "Instructions for the Devil's Advocate agent.",
        ],
    ];

    private const MAX_CONTENT_LENGTH = 100_000;

    private string $promptsDir;

    public function __construct(?string $promptsDir = null)
    {
        $this->promptsDir = $promptsDir
            ?? realpath(__DIR__ . '/../../../storage/prompts')
            ?: (__DIR__ . '/../../../storage/prompts');
    }

    /** Return all allowed policy descriptors (no content). */
    public function list(): array
    {
        $items = [];
        foreach (self::ALLOWED as $id => $meta) {
            $items[] = [
                'id'          => $id,
                'title'       => $meta['title'],
                'filename'    => $meta['filename'],
                'description' => $meta['description'],
            ];
        }
        return $items;
    }

    /**
     * Return a single policy with its content.
     *
     * @throws \InvalidArgumentException  for unknown id
     * @throws \RuntimeException          for unreadable file
     */
    public function get(string $id): array
    {
        $meta = $this->resolveMeta($id);
        $path = $this->resolvePath($meta['filename']);

        if (!is_file($path)) {
            if ($id === 'social-dynamics-policy') {
                $this->writeDefault($path);
            } else {
                throw new \RuntimeException("Policy file not found: {$meta['filename']}");
            }
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read policy file: {$meta['filename']}");
        }

        return [
            'id'          => $id,
            'title'       => $meta['title'],
            'filename'    => $meta['filename'],
            'description' => $meta['description'],
            'content'     => $content,
        ];
    }

    /**
     * Persist updated content for an allowed policy.
     *
     * @throws \InvalidArgumentException  for unknown id or invalid content
     * @throws \RuntimeException          for write failures
     */
    public function save(string $id, string $content): void
    {
        $meta = $this->resolveMeta($id);
        $path = $this->resolvePath($meta['filename']);

        if (mb_strlen($content, 'UTF-8') > self::MAX_CONTENT_LENGTH) {
            throw new \InvalidArgumentException(
                'Content exceeds maximum allowed length (' . self::MAX_CONTENT_LENGTH . ' chars).'
            );
        }

        // Atomic write: write to temp then rename
        $tmp = $path . '.tmp.' . uniqid('', true);
        $written = file_put_contents($tmp, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException("Cannot write policy file: {$meta['filename']}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot atomically replace policy file: {$meta['filename']}");
        }
    }

    /** Verify that an id is whitelisted — throws on unknown. */
    public function assertAllowed(string $id): void
    {
        $this->resolveMeta($id);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function resolveMeta(string $id): array
    {
        if (!isset(self::ALLOWED[$id])) {
            throw new \InvalidArgumentException("Unknown policy id: '{$id}'.");
        }
        return self::ALLOWED[$id];
    }

    /**
     * Resolves and validates the path (prevents path-traversal even if ALLOWED is
     * somehow modified — belt-and-suspenders check).
     */
    private function resolvePath(string $filename): string
    {
        // Strip any directory components from the filename itself
        $safe = basename($filename);
        $path = $this->promptsDir . DIRECTORY_SEPARATOR . $safe;

        // realpath() returns false if file doesn't exist yet; we check the parent
        $dirReal = realpath($this->promptsDir);
        if ($dirReal === false) {
            throw new \RuntimeException("Prompts directory not found: {$this->promptsDir}");
        }

        // Ensure the resolved path starts with the canonical prompts directory
        $resolved = str_replace('/', DIRECTORY_SEPARATOR, $dirReal . DIRECTORY_SEPARATOR . $safe);
        if (!str_starts_with($resolved, $dirReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Path traversal detected.');
        }

        return $resolved;
    }

    private function writeDefault(string $path): void
    {
        $default = $this->defaultSocialDynamicsPolicy();
        $written = file_put_contents($path, $default, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Cannot create default social-dynamics-policy.md');
        }
    }

    private function defaultSocialDynamicsPolicy(): string
    {
        return <<<'MD'
# Social Dynamics Policy

## Purpose

Make structured multi-agent debates more relational, explicit and auditable.

## Rules

- React explicitly to at least one previous agent when previous contributions exist.
- Support agents whose reasoning strengthens your position.
- Challenge agents whose assumptions, evidence or reasoning are weak.
- You may form temporary alliances when useful.
- You may strongly disagree, but remain professional.
- Attack reasoning, assumptions and evidence — never the person.
- Be forceful, not toxic.
- Do not create autonomous loops.

## Recommended Format

## Target Agent
agent-id

## Position
GO | NO-GO | ITERATE

## Response
...

## Alignment
...

## Opposition
...

## Challenge
...

## Alliance
...
MD;
    }
}
