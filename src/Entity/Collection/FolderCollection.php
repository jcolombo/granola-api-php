<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Collection;

use Jcolombo\GranolaApiPhp\Entity\AbstractCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\Folder;

/**
 * A cursor-paginated list of folders, sorted alphabetically by Granola.
 *
 * Hierarchy comes back flat, as a `parent_folder_id` on each folder, so the
 * tree helpers here rebuild it locally. They assume the whole list is loaded —
 * call fetchAll() (or Folder::all()) first.
 */
class FolderCollection extends AbstractCollection
{
    public const RESULT_KEY = 'folders';

    protected const PAGE_SIZE_CONFIG = 'foldersPageSize';

    /**
     * Top-level folders only.
     *
     * @return list<Folder>
     */
    public function roots(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn ($folder): bool => $folder instanceof Folder && $folder->isRoot()
        ));
    }

    /**
     * Direct children of one folder.
     *
     * @return list<Folder>
     */
    public function childrenOf(Folder|string $parent): array
    {
        $parentId = $parent instanceof Folder ? $parent->id() : $parent;

        return array_values(array_filter(
            $this->all(),
            static fn ($folder): bool => $folder instanceof Folder && $folder->parentId() === $parentId
        ));
    }

    /**
     * Every descendant of one folder, at any depth.
     *
     * @return list<Folder>
     */
    public function descendantsOf(Folder|string $parent): array
    {
        $out = [];
        $queue = $this->childrenOf($parent);

        while ($queue !== []) {
            $folder = array_shift($queue);
            $out[] = $folder;
            foreach ($this->childrenOf($folder) as $child) {
                $queue[] = $child;
            }
        }

        return $out;
    }

    /**
     * The chain from the root down to this folder, inclusive.
     *
     * @return list<Folder>
     */
    public function ancestryOf(Folder|string $folder): array
    {
        $current = $folder instanceof Folder ? $folder : $this->find($folder);
        $chain = [];
        $guard = 0;

        while ($current instanceof Folder && $guard++ < 100) {
            array_unshift($chain, $current);
            $parentId = $current->parentId();
            $current = $parentId === null ? null : $this->find($parentId);
        }

        return $chain;
    }

    /**
     * A readable path: "Clients / Acme / Weekly sync".
     */
    public function pathOf(Folder|string $folder, string $separator = ' / '): string
    {
        return implode($separator, array_map(
            static fn (Folder $f): string => (string) $f->name(),
            $this->ancestryOf($folder)
        ));
    }

    /**
     * The full hierarchy as nested arrays, roots first.
     *
     * Each node is ['folder' => Folder, 'children' => [...]].
     *
     * @return list<array{folder: Folder, children: list<mixed>}>
     */
    public function tree(): array
    {
        return array_map([$this, 'buildNode'], $this->roots());
    }

    // ── Typed accessors ─────────────────────────────────────────────────

    /**
     * @return list<Folder>
     */
    public function all(): array
    {
        /** @var list<Folder> */
        return parent::all();
    }

    public function first(): ?Folder
    {
        $folder = parent::first();
        return $folder instanceof Folder ? $folder : null;
    }

    public function last(): ?Folder
    {
        $folder = parent::last();
        return $folder instanceof Folder ? $folder : null;
    }

    public function find(string $id): ?Folder
    {
        $folder = parent::find($id);
        return $folder instanceof Folder ? $folder : null;
    }

    public function current(): Folder
    {
        /** @var Folder */
        return parent::current();
    }

    public function offsetGet(mixed $offset): ?Folder
    {
        $folder = parent::offsetGet($offset);
        return $folder instanceof Folder ? $folder : null;
    }

    /**
     * @return \Generator<int, Folder>
     */
    public function each(): \Generator
    {
        yield from parent::each();
    }

    /**
     * @return array{folder: Folder, children: list<mixed>}
     */
    private function buildNode(Folder $folder): array
    {
        return [
            'folder' => $folder,
            'children' => array_map([$this, 'buildNode'], $this->childrenOf($folder)),
        ];
    }
}
