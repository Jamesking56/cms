<?php

namespace Tests\StaticCaching;

use Facades\Tests\Factories\EntryFactory;
use Mockery;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Assets\AssetContainer;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Globals\GlobalSet;
use Statamic\Contracts\Structures\Nav;
use Statamic\Contracts\Taxonomies\Taxonomy;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\DefaultInvalidator as Invalidator;
use Statamic\Structures\CollectionStructure;
use Tests\TestCase;

class DefaultInvalidatorTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
    }

    /** @test */
    public function specifying_all_as_invalidation_rule_will_just_flush_the_cache()
    {
        $cacher = Mockery::mock(Cacher::class)->shouldReceive('flush')->once()->getMock();
        $item = Mockery::mock(Entry::class);

        $invalidator = new Invalidator($cacher, 'all');

        $this->assertNull($invalidator->invalidate($item));
    }

    /** @test */
    public function assets_can_trigger_url_invalidation()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/page/one', '/page/two']);
        });

        $container = tap(Mockery::mock(AssetContainer::class), function ($m) {
            $m->shouldReceive('handle')->andReturn('main');
        });

        $asset = tap(Mockery::mock(Asset::class), function ($m) use ($container) {
            $m->shouldReceive('container')->andReturn($container);
        });

        $invalidator = new Invalidator($cacher, [
            'assets' => [
                'main' => [
                    'urls' => [
                        '/page/one',
                        '/page/two',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($asset));
    }

    /** @test */
    public function collection_urls_can_be_invalidated()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrl')->with('/my/test/collection', 'http://test.com')->once();
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/blog/one', '/blog/two']);
        });

        $collection = tap(Mockery::mock(Collection::class), function ($m) {
            $m->shouldReceive('absoluteUrl')->andReturn('http://test.com/my/test/collection');
            $m->shouldReceive('handle')->andReturn('blog');
        });

        $invalidator = new Invalidator($cacher, [
            'collections' => [
                'blog' => [
                    'urls' => [
                        '/blog/one',
                        '/blog/two',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($collection));
    }

    /** @test */
    public function collection_urls_can_be_invalidated_by_an_entry()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrl')->with('/my/test/entry', 'http://test.com')->once();
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/blog/one', '/blog/two']);
        });

        $entry = tap(Mockery::mock(Entry::class), function ($m) {
            $m->shouldReceive('isRedirect')->andReturn(false);
            $m->shouldReceive('absoluteUrl')->andReturn('http://test.com/my/test/entry');
            $m->shouldReceive('collectionHandle')->andReturn('blog');
            $m->shouldReceive('descendants')->andReturn(collect());
            $m->shouldReceive('parent')->andReturnNull();
            $m->shouldReceive('toAugmentedCollection')
                ->andReturnSelf()
                ->shouldReceive('merge')
                ->andReturn(collect([
                    'parent_uri' => null,
                ]));
        });

        $invalidator = new Invalidator($cacher, [
            'collections' => [
                'blog' => [
                    'urls' => [
                        '/blog/one',
                        '/blog/two',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($entry));
    }

    /** @test */
    public function collection_urls_can_be_invalidated_by_an_entry_with_antlers_in_invalidation_rules()
    {
        $collection = tap(\Statamic\Facades\Collection::make('pages')->routes('{parent_uri}/{slug}')->structureContents(['root' => true]))->save();
        EntryFactory::collection('pages')->id('home')->slug('home')->create();
        EntryFactory::collection('pages')->id('about')->slug('about')->create();
        $entry = EntryFactory::collection('pages')->id('team')->slug('team')->data(['test' => 'foo', 'favourite_color' => 'purple'])->create();

        $collection->structureContents(['root' => true, 'slugs' => true])->save();
        $collection->structure()->in('en')->tree([
            ['entry' => 'home'],
            ['entry' => 'about', 'children' => [
                ['entry' => 'team'],
            ]],
        ])->save();

        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrl')->with('/about/team', 'http://localhost')->once();
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/about', '/test/foo', '/purple']);
        });

        $invalidator = new Invalidator($cacher, [
            'collections' => [
                'pages' => [
                    'urls' => [
                        '{parent_uri}',
                        '/test/{test}',
                        '{{ if favourite_color == "purple" }}/purple{{ /if }}',
                        '{{ if favourite_color == "red" }}/red{{ /if }}',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($entry));
    }

    /** @test */
    public function entry_urls_are_not_invalidated_by_an_entry_with_a_redirect()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrl')->never();
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/blog/one', '/blog/two']);
        });

        $entry = tap(Mockery::mock(Entry::class), function ($m) {
            $m->shouldReceive('isRedirect')->andReturn(true);
            $m->shouldReceive('absoluteUrl')->andReturn('http://test.com/my/test/entry');
            $m->shouldReceive('collectionHandle')->andReturn('blog');
            $m->shouldReceive('descendants')->andReturn(collect());
            $m->shouldReceive('parent')->andReturnNull();
            $m->shouldReceive('toAugmentedCollection')
                ->andReturnSelf()
                ->shouldReceive('merge')
                ->andReturn(collect([
                    'parent_uri' => null,
                ]));
        });

        $invalidator = new Invalidator($cacher, [
            'collections' => [
                'blog' => [
                    'urls' => [
                        '/blog/one',
                        '/blog/two',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($entry));
    }

    /** @test */
    public function taxonomy_urls_can_be_invalidated()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrl')->with('/my/test/term', 'http://test.com')->once();
            $cacher->shouldReceive('invalidateUrl')->with('/my/collection/tags/term', 'http://test.com')->once();
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/tags/one', '/tags/two', '/test/foo', '/purple']);
        });

        $collection = Mockery::mock(Collection::class);

        $taxonomy = tap(Mockery::mock(Taxonomy::class), function ($m) use ($collection) {
            $m->shouldReceive('collections')->andReturn(collect([$collection]));
        });

        $term = tap(Mockery::mock(Term::class), function ($m) use ($taxonomy) {
            $m->shouldReceive('absoluteUrl')->andReturn('http://test.com/my/test/term', 'http://test.com/my/collection/tags/term');
            $m->shouldReceive('taxonomyHandle')->andReturn('tags');
            $m->shouldReceive('taxonomy')->andReturn($taxonomy);
            $m->shouldReceive('collection')->andReturn($m);
            $m->shouldReceive('toAugmentedCollection')
                ->andReturn(collect([
                    'test' => 'foo',
                    'favourite_color' => 'purple',
                ]));
        });

        $invalidator = new Invalidator($cacher, [
            'taxonomies' => [
                'tags' => [
                    'urls' => [
                        '/tags/one',
                        '/tags/two',
                        '/test/{test}',
                        '{{ if favourite_color == "purple" }}/purple{{ /if }}',
                        '{{ if favourite_color == "red" }}/red{{ /if }}',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($term));
    }

    /** @test */
    public function navigation_urls_can_be_invalidated()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/one', '/two', '/test/foo', '/purple']);
        });

        $nav = tap(Mockery::mock(Nav::class), function ($m) {
            $m->shouldReceive('handle')->andReturn('links');
            $m->shouldReceive('toAugmentedCollection')
                ->andReturn(collect([
                    'test' => 'foo',
                    'favourite_color' => 'purple',
                ]));
        });

        $invalidator = new Invalidator($cacher, [
            'navigation' => [
                'links' => [
                    'urls' => [
                        '/one',
                        '/two',
                        '/test/{test}',
                        '{{ if favourite_color == "purple" }}/purple{{ /if }}',
                        '{{ if favourite_color == "red" }}/red{{ /if }}',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($nav));
    }

    /** @test */
    public function globals_urls_can_be_invalidated()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/one', '/two', '/test/foo', '/purple']);
        });

        $set = tap(Mockery::mock(GlobalSet::class), function ($m) {
            $m->shouldReceive('handle')->andReturn('social');
            $m->shouldReceive('toAugmentedCollection')
                ->andReturn(collect([
                    'test' => 'foo',
                    'favourite_color' => 'purple',
                ]));
        });

        $invalidator = new Invalidator($cacher, [
            'globals' => [
                'social' => [
                    'urls' => [
                        '/one',
                        '/two',
                        '/test/{test}',
                        '{{ if favourite_color == "purple" }}/purple{{ /if }}',
                        '{{ if favourite_color == "red" }}/red{{ /if }}',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($set));
    }

    /** @test */
    public function form_urls_can_be_invalidated()
    {
        $cacher = tap(Mockery::mock(Cacher::class), function ($cacher) {
            $cacher->shouldReceive('invalidateUrls')->once()->with(['/one', '/two']);
        });

        $form = tap(Mockery::mock(Form::class), function ($m) {
            $m->shouldReceive('handle')->andReturn('newsletter');
        });

        $invalidator = new Invalidator($cacher, [
            'forms' => [
                'newsletter' => [
                    'urls' => [
                        '/one',
                        '/two',
                    ],
                ],
            ],
        ]);

        $this->assertNull($invalidator->invalidate($form));
    }
}
