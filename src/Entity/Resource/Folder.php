<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Resource;

use Jcolombo\GranolaApiPhp\Entity\AbstractResource;
use Jcolombo\GranolaApiPhp\Entity\Collection\FolderCollection;
use Jcolombo\GranolaApiPhp\Granola;

/**
 * A folder (or space) that notes live in.
 *
 * Granola has no "get one folder" endpoint — folders are only ever listed, so
 * fetch them all and index locally:
 *
 *     $folders = Folder::all();
 *     $folder  = $folders->find('fol_4y6LduVdwSKC27');
 *     $tree    = $folders->tree();
 *
 * Folder IDs are what restrict a webhook endpoint's deliveries and filter
 * Note::list(), and both include child folders automatically.
 */
class Folder extends AbstractResource
{
    public const LABEL = 'Folder';
    public const API_PATH = 'v1/folders';
    public const OBJECT_TYPE = 'folder';
    public const ID_PREFIX = 'fol_';

    public const PROP_TYPES = [
        'id' => 'id',
        'object' => 'text',
        'name' => 'text',
        'parent_folder_id' => 'id',
    ];

    /**
     * Start a cursor-paginated listing of folders.
     */
    public static function list(null|string|Granola $connection = null): FolderCollection
    {
        /** @var FolderCollection */
        return parent::list($connection);
    }

    /**
     * Fetch every folder the key can see, following all pages.
     */
    public static function all(null|string|Granola $connection = null): FolderCollection
    {
        return static::list($connection)->fetchAll();
    }

    public function name(): ?string
    {
        $name = $this->get('name');
        return $name === null ? null : (string) $name;
    }

    public function parentId(): ?string
    {
        $parent = $this->get('parent_folder_id');
        return $parent === null ? null : (string) $parent;
    }

    /**
     * True when this folder sits at the top level.
     */
    public function isRoot(): bool
    {
        return $this->parentId() === null;
    }
}
