<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Entity\Resource\Folder;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;

final class FolderTest extends GranolaTestCase
{
    public function testFoldersHydrateAndReportTheirDepth(): void
    {
        $api = MockApi::make(MockApi::fixture('folders.list'));

        $folders = Folder::all($api->granola);

        self::assertCount(4, $folders);
        self::assertTrue($folders->find('fol_a74g2hvl98iUHG')?->isRoot());
        self::assertFalse($folders->find('fol_4y6LduVdwSKC27')?->isRoot());
        self::assertSame('fol_a74g2hvl98iUHG', $folders->find('fol_4y6LduVdwSKC27')?->parentId());
    }

    public function testRootsAndChildrenRebuildTheHierarchyLocally(): void
    {
        $api = MockApi::make(MockApi::fixture('folders.list'));
        $folders = Folder::all($api->granola);

        self::assertSame(['Product', 'Sales'], array_map(
            static fn (Folder $f): string => (string) $f->name(),
            $folders->roots()
        ));
        self::assertSame(['Top secret recipes'], array_map(
            static fn (Folder $f): string => (string) $f->name(),
            $folders->childrenOf('fol_a74g2hvl98iUHG')
        ));
    }

    public function testDescendantsReachEveryDepth(): void
    {
        $api = MockApi::make(MockApi::fixture('folders.list'));
        $folders = Folder::all($api->granola);

        $names = array_map(
            static fn (Folder $f): string => (string) $f->name(),
            $folders->descendantsOf('fol_a74g2hvl98iUHG')
        );

        self::assertSame(['Top secret recipes', 'Greek'], $names);
    }

    public function testPathOfRendersTheFullAncestry(): void
    {
        $api = MockApi::make(MockApi::fixture('folders.list'));
        $folders = Folder::all($api->granola);

        self::assertSame('Product / Top secret recipes / Greek', $folders->pathOf('fol_9m2QpRsTuVwX10'));
        self::assertSame('Sales', $folders->pathOf('fol_5b8CdEfGhIjKl3'));
    }

    public function testTreeNestsChildrenUnderTheirParents(): void
    {
        $api = MockApi::make(MockApi::fixture('folders.list'));
        $tree = Folder::all($api->granola)->tree();

        self::assertCount(2, $tree);
        self::assertSame('Product', $tree[0]['folder']->name());
        self::assertSame('Top secret recipes', $tree[0]['children'][0]['folder']->name());
        self::assertSame('Greek', $tree[0]['children'][0]['children'][0]['folder']->name());
        self::assertSame([], $tree[1]['children']);
    }
}
