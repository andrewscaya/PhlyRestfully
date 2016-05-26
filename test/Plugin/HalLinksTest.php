<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfullyTest\Plugin;

use PhlyRestfully\HalCollection;
use PhlyRestfully\HalResource;
use PhlyRestfully\Link;
use PhlyRestfully\MetadataMap;
use PhlyRestfully\Plugin\HalLinks;
use PhlyRestfully\ResourceController;
use PHPUnit_Framework_TestCase as TestCase;
use Zend\Http\Request;
use Zend\Hydrator;
use Zend\Mvc\Router\Http\TreeRouteStack;
use Zend\Mvc\Router\RouteMatch;
use Zend\Mvc\Router\SimpleRouteStack;
use Zend\Mvc\Router\Http\Segment;
use Zend\Mvc\MvcEvent;
use Zend\Uri\Http;
use Zend\Uri\Uri;
use Zend\View\Helper\Url as UrlHelper;
use Zend\View\Helper\ServerUrl as ServerUrlHelper;

/**
 * @subpackage UnitTest
 */
class HalLinksTest extends TestCase
{
    public function setUp()
    {
        $this->router = $router = new TreeRouteStack();
        $route = new Segment('/resource[/[:id]]');
        $router->addRoute('resource', $route);
        $route2 = new Segment('/help');
        $router->addRoute('docs', $route2);
        $router->addRoute('hostname', [

            'type' => 'hostname',
            'options' => [
                'route' => 'localhost.localdomain',
            ],

            'child_routes' => [
                'resource' => [
                    'type' => 'segment',
                    'options' => [
                        'route' => '/resource[/:id]'
                    ],
                    'may_terminate' => true,
                    'child_routes' => [
                        'children' => [
                            'type' => 'literal',
                            'options' => [
                                'route' => '/children',
                            ],
                        ],
                    ],
                ],
                'users' => [
                    'type' => 'segment',
                    'options' => [
                        'route' => '/users[/:id]'
                    ]
                ],
                'contacts' => [
                    'type' => 'segment',
                    'options' => [
                        'route' => '/contacts[/:id]'
                    ]
                ],
                'embedded' => [
                    'type' => 'segment',
                    'options' => [
                        'route' => '/embedded[/:id]'
                    ]
                ],
                'embedded_custom' => [
                    'type' => 'segment',
                    'options' => [
                        'route' => '/embedded_custom[/:custom_id]'
                    ]
                ],
            ]
        ]);

        $this->event = $event = new MvcEvent();
        $event->setRouter($router);
        $router->setRequestUri(new Http('http://localhost.localdomain/resource'));

        $controller = $this->controller = $this->getMock(ResourceController::class);
        $controller->expects($this->any())
            ->method('getEvent')
            ->will($this->returnValue($event));

        $this->urlHelper = $urlHelper = new UrlHelper();
        $urlHelper->setRouter($router);

        $this->serverUrlHelper = $serverUrlHelper = new ServerUrlHelper();
        $serverUrlHelper->setScheme('http');
        $serverUrlHelper->setHost('localhost.localdomain');

        $this->plugin = $plugin = new HalLinks();
        $plugin->setController($controller);
        $plugin->setUrlHelper($urlHelper);
        $plugin->setServerUrlHelper($serverUrlHelper);
    }

    public function assertRelationalLinkContains($match, $relation, $resource)
    {
        $this->assertInternalType('array', $resource);
        $this->assertArrayHasKey('_links', $resource);
        $links = $resource['_links'];
        $this->assertInternalType('array', $links);
        $this->assertArrayHasKey($relation, $links);
        $link = $links[$relation];
        $this->assertInternalType('array', $link);
        $this->assertArrayHasKey('href', $link);
        $href = $link['href'];
        $this->assertInternalType('string', $href);
        $this->assertContains($match, $href);
    }

    public function testCreateLinkSkipServerUrlHelperIfSchemeExists()
    {
        $url = $this->plugin->createLink('hostname/resource');
        $this->assertEquals('http://localhost.localdomain/resource', $url);
    }

    public function testLinkCreationWithoutIdCreatesFullyQualifiedLink()
    {
        $url = $this->plugin->createLink('resource');
        $this->assertEquals('http://localhost.localdomain/resource', $url);
    }

    public function testLinkCreationWithIdCreatesFullyQualifiedLink()
    {
        $url = $this->plugin->createLink('resource', 123);
        $this->assertEquals('http://localhost.localdomain/resource/123', $url);
    }

    public function testLinkCreationFromHalResource()
    {
        $self = new Link('self');
        $self->setRoute('resource', ['id' => 123]);
        $docs = new Link('describedby');
        $docs->setRoute('docs');
        $resource = new HalResource([], 123);
        $resource->getLinks()->add($self)->add($docs);
        $links = $this->plugin->fromResource($resource);

        $this->assertInternalType('array', $links);
        $this->assertArrayHasKey('self', $links, var_export($links, 1));
        $this->assertArrayHasKey('describedby', $links, var_export($links, 1));

        $selfLink = $links['self'];
        $this->assertInternalType('array', $selfLink);
        $this->assertArrayHasKey('href', $selfLink);
        $this->assertEquals('http://localhost.localdomain/resource/123', $selfLink['href']);

        $docsLink = $links['describedby'];
        $this->assertInternalType('array', $docsLink);
        $this->assertArrayHasKey('href', $docsLink);
        $this->assertEquals('http://localhost.localdomain/help', $docsLink['href']);
    }

    public function testRendersEmbeddedCollectionsInsideResources()
    {
        $collection = new HalCollection(
            [
                (object) ['id' => 'foo', 'name' => 'foo'],
                (object) ['id' => 'bar', 'name' => 'bar'],
                (object) ['id' => 'baz', 'name' => 'baz'],
            ],
            'hostname/contacts'
        );
        $resource = new HalResource(
            (object) [
                'id'       => 'user',
                'contacts' => $collection,
            ],
            'user'
        );
        $self = new Link('self');
        $self->setRoute('hostname/users', ['id' => 'user']);
        $resource->getLinks()->add($self);

        $rendered = $this->plugin->renderResource($resource);
        $this->assertRelationalLinkContains('/users/', 'self', $rendered);

        $this->assertArrayHasKey('_embedded', $rendered);
        $embed = $rendered['_embedded'];
        $this->assertArrayHasKey('contacts', $embed);
        $contacts = $embed['contacts'];
        $this->assertInternalType('array', $contacts);
        $this->assertEquals(3, count($contacts));
        foreach ($contacts as $contact) {
            $this->assertInternalType('array', $contact);
            $this->assertRelationalLinkContains('/contacts/', 'self', $contact);
        }
    }

    public function testRendersEmbeddedResourcesInsideResourcesBasedOnMetadataMap()
    {
        $object = new TestAsset\Resource('foo', 'Foo');
        $object->first_child  = new TestAsset\EmbeddedResource('bar', 'Bar');
        $object->second_child = new TestAsset\EmbeddedResourceWithCustomIdentifier('baz', 'Baz');
        $resource = new HalResource($object, 'foo');
        $self = new Link('self');
        $self->setRoute('hostname/resource', ['id' => 'foo']);
        $resource->getLinks()->add($self);

        $metadata = new MetadataMap([
            TestAsset\Resource::class => [
                'hydrator' => Hydrator\ObjectProperty::class,
                'route'    => 'hostname/resource',
            ],
            TestAsset\EmbeddedResource::class => [
                'hydrator' => Hydrator\ObjectProperty::class,
                'route'    => 'hostname/embedded',
            ],
            TestAsset\EmbeddedResourceWithCustomIdentifier::class => [
                'hydrator'        => Hydrator\ObjectProperty::class,
                'route'           => 'hostname/embedded_custom',
                'identifier_name' => 'custom_id',
            ],
        ]);

        $this->plugin->setMetadataMap($metadata);

        $rendered = $this->plugin->renderResource($resource);
        $this->assertRelationalLinkContains('/resource/foo', 'self', $rendered);

        $this->assertArrayHasKey('_embedded', $rendered);
        $embed = $rendered['_embedded'];
        $this->assertEquals(2, count($embed));
        $this->assertArrayHasKey('first_child', $embed);
        $this->assertArrayHasKey('second_child', $embed);

        $first = $embed['first_child'];
        $this->assertInternalType('array', $first);
        $this->assertRelationalLinkContains('/embedded/bar', 'self', $first);

        $second = $embed['second_child'];
        $this->assertInternalType('array', $second);
        $this->assertRelationalLinkContains('/embedded_custom/baz', 'self', $second);
    }

    public function testRendersEmbeddedCollectionsInsideResourcesBasedOnMetadataMap()
    {
        $collection = new TestAsset\Collection(
            [
                (object) ['id' => 'foo', 'name' => 'foo'],
                (object) ['id' => 'bar', 'name' => 'bar'],
                (object) ['id' => 'baz', 'name' => 'baz'],
            ]
        );

        $metadata = new MetadataMap([
            TestAsset\Collection::class => [
                'is_collection'  => true,
                'route'          => 'hostname/contacts',
                'resource_route' => 'hostname/embedded',
            ],
        ]);

        $this->plugin->setMetadataMap($metadata);

        $resource = new HalResource(
            (object) [
                'id'       => 'user',
                'contacts' => $collection,
            ],
            'user'
        );
        $self = new Link('self');
        $self->setRoute('hostname/users', ['id' => 'user']);
        $resource->getLinks()->add($self);

        $rendered = $this->plugin->renderResource($resource);

        $this->assertRelationalLinkContains('/users/', 'self', $rendered);

        $this->assertArrayHasKey('_embedded', $rendered);
        $embed = $rendered['_embedded'];
        $this->assertArrayHasKey('contacts', $embed);
        $contacts = $embed['contacts'];
        $this->assertInternalType('array', $contacts);
        $this->assertEquals(3, count($contacts));
        foreach ($contacts as $contact) {
            $this->assertInternalType('array', $contact);
            $this->assertArrayHasKey('id', $contact);
            $this->assertRelationalLinkContains('/embedded/' . $contact['id'], 'self', $contact);
        }
    }

    public function testRendersEmbeddedCollectionsInsideCollectionsBasedOnMetadataMap()
    {
        $childCollection = new TestAsset\Collection(
            [
                (object) ['id' => 'foo', 'name' => 'foo'],
                (object) ['id' => 'bar', 'name' => 'bar'],
                (object) ['id' => 'baz', 'name' => 'baz'],
            ]
        );
        $resource = new TestAsset\Resource('spock', 'Spock');
        $resource->first_child = $childCollection;

        $metadata = new MetadataMap([
            TestAsset\Collection::class => [
                'is_collection'  => true,
                'route'          => 'hostname/contacts',
                'resource_route' => 'hostname/embedded',
            ],
            TestAsset\Resource::class => [
                'hydrator' => Hydrator\ObjectProperty::class,
                'route'    => 'hostname/resource',
            ],
        ]);

        $this->plugin->setMetadataMap($metadata);

        $collection = new HalCollection([$resource], 'hostname/resource');
        $self = new Link('self');
        $self->setRoute('hostname/resource');
        $collection->getLinks()->add($self);
        $collection->setCollectionName('resources');

        $rendered = $this->plugin->renderCollection($collection);

        $this->assertRelationalLinkContains('/resource', 'self', $rendered);

        $this->assertArrayHasKey('_embedded', $rendered);
        $embed = $rendered['_embedded'];
        $this->assertArrayHasKey('resources', $embed);
        $resources = $embed['resources'];
        $this->assertInternalType('array', $resources);
        $this->assertEquals(1, count($resources));

        $resource = array_shift($resources);
        $this->assertInternalType('array', $resource);
        $this->assertArrayHasKey('_embedded', $resource);
        $this->assertInternalType('array', $resource['_embedded']);
        $this->assertArrayHasKey('first_child', $resource['_embedded']);
        $this->assertInternalType('array', $resource['_embedded']['first_child']);

        foreach ($resource['_embedded']['first_child'] as $contact) {
            $this->assertInternalType('array', $contact);
            $this->assertArrayHasKey('id', $contact);
            $this->assertRelationalLinkContains('/embedded/' . $contact['id'], 'self', $contact);
        }
    }

    public function testWillNotAllowInjectingASelfRelationMultipleTimes()
    {
        $resource = new HalResource([
            'id'  => 1,
            'foo' => 'bar',
        ], 1);
        $links = $resource->getLinks();

        $this->assertFalse($links->has('self'));

        $this->plugin->injectSelfLink($resource, 'hostname/resource');

        $this->assertTrue($links->has('self'));
        $link = $links->get('self');
        $this->assertInstanceof(Link::class, $link);

        $this->plugin->injectSelfLink($resource, 'hostname/resource');
        $this->assertTrue($links->has('self'));
        $link = $links->get('self');
        $this->assertInstanceof(Link::class, $link);
    }

    /**
     * @group 71
     */
    public function testRenderingEmbeddedHalResourceEmbedsResource()
    {
        $embedded = new HalResource((object) ['id' => 'foo', 'name' => 'foo'], 'foo');
        $self = new Link('self');
        $self->setRoute('hostname/contacts', ['id' => 'foo']);
        $embedded->getLinks()->add($self);

        $resource = new HalResource((object) ['id' => 'user', 'contact' => $embedded], 'user');
        $self = new Link('self');
        $self->setRoute('hostname/users', ['id' => 'user']);
        $resource->getLinks()->add($self);

        $rendered = $this->plugin->renderResource($resource);

        $this->assertRelationalLinkContains('/users/user', 'self', $rendered);
        $this->assertArrayHasKey('_embedded', $rendered);
        $this->assertInternalType('array', $rendered['_embedded']);
        $this->assertArrayHasKey('contact', $rendered['_embedded']);
        $contact = $rendered['_embedded']['contact'];
        $this->assertRelationalLinkContains('/contacts/foo', 'self', $contact);
    }

    /**
     * @group 71
     */
    public function testRenderingCollectionRendersAllLinksInEmbeddedResources()
    {
        $embedded = new HalResource((object) ['id' => 'foo', 'name' => 'foo'], 'foo');
        $links = $embedded->getLinks();
        $self = new Link('self');
        $self->setRoute('hostname/users', ['id' => 'foo']);
        $links->add($self);
        $phones = new Link('phones');
        $phones->setUrl('http://localhost.localdomain/users/foo/phones');
        $links->add($phones);

        $collection = new HalCollection([$embedded]);
        $collection->setCollectionName('users');
        $self = new Link('self');
        $self->setRoute('hostname/users');
        $collection->getLinks()->add($self);

        $rendered = $this->plugin->renderCollection($collection);

        $this->assertRelationalLinkContains('/users', 'self', $rendered);
        $this->assertArrayHasKey('_embedded', $rendered);
        $this->assertInternalType('array', $rendered['_embedded']);
        $this->assertArrayHasKey('users', $rendered['_embedded']);

        $users = $rendered['_embedded']['users'];
        $this->assertInternalType('array', $users);
        $user = array_shift($users);

        $this->assertRelationalLinkContains('/users/foo', 'self', $user);
        $this->assertRelationalLinkContains('/users/foo/phones', 'phones', $user);
    }
    
    public function testRenderingCollectionRendersAllLinksInEmbeddedArrayResourcesWithCustomIdentifier()
    {
        $embedded = array('custom_id' => 'foo', 'name' => 'foo');

        $collection = new HalCollection(array($embedded));
        $collection->setCollectionName('embedded_custom');
        $collection->setCollectionRoute('hostname/embedded_custom');
        $collection->setResourceRoute('hostname/embedded_custom');
        $collection->setIdentifierName('custom_id');
        $self = new Link('self');
        $self->setRoute('hostname/embedded_custom');
        $collection->getLinks()->add($self);

        $rendered = $this->plugin->renderCollection($collection);

        $this->assertRelationalLinkContains('/embedded_custom', 'self', $rendered);
        $this->assertArrayHasKey('_embedded', $rendered);
        $this->assertInternalType('array', $rendered['_embedded']);
        $this->assertArrayHasKey('embedded_custom', $rendered['_embedded']);

        $embeddedCustoms = $rendered['_embedded']['embedded_custom'];
        $this->assertInternalType('array', $embeddedCustoms);
        $embeddedCustom = array_shift($embeddedCustoms);

        $this->assertRelationalLinkContains('/embedded_custom/foo', 'self', $embeddedCustom);
    }

    public function testResourcesFromCollectionCanUseHydratorSetInMetadataMap()
    {
        $object   = new TestAsset\ResourceWithProtectedProperties('foo', 'Foo');
        $resource = new HalResource($object, 'foo');

        $metadata = new MetadataMap([
            TestAsset\ResourceWithProtectedProperties::class => [
                'hydrator' => 'ArraySerializable',
                'route'    => 'hostname/resource',
            ],
        ]);

        $collection = new HalCollection([$resource]);
        $collection->setCollectionName('resource');
        $collection->setCollectionRoute('hostname/resource');

        $this->plugin->setMetadataMap($metadata);

        $test = $this->plugin->renderCollection($collection);

        $this->assertInternalType('array', $test);
        $this->assertArrayHasKey('_embedded', $test);
        $this->assertInternalType('array', $test['_embedded']);
        $this->assertArrayHasKey('resource', $test['_embedded']);
        $this->assertInternalType('array', $test['_embedded']['resource']);

        $resources = $test['_embedded']['resource'];
        $testResource = array_shift($resources);
        $this->assertInternalType('array', $testResource);
        $this->assertArrayHasKey('id', $testResource);
        $this->assertArrayHasKey('name', $testResource);
    }

    /**
     * @group 79
     */
    public function testInjectsLinksFromMetadataWhenCreatingResource()
    {
        $object = new TestAsset\Resource('foo', 'Foo');
        $resource = new HalResource($object, 'foo');

        $metadata = new MetadataMap([
            TestAsset\Resource::class => [
                'hydrator' => Hydrator\ObjectProperty::class,
                'route'    => 'hostname/resource',
                'links'    => [
                    [
                        'rel' => 'describedby',
                        'url' => 'http://example.com/api/help/resource',
                    ],
                    [
                        'rel' => 'children',
                        'route' => [
                            'name' => 'resource/children',
                        ],
                    ],
                ],
            ],
        ]);

        $this->plugin->setMetadataMap($metadata);
        $resource = $this->plugin->createResourceFromMetadata(
            $object,
            $metadata->get(TestAsset\Resource::class)
        );
        $this->assertInstanceof(HalResource::class, $resource);
        $links = $resource->getLinks();
        $this->assertTrue($links->has('describedby'));
        $this->assertTrue($links->has('children'));

        $describedby = $links->get('describedby');
        $this->assertTrue($describedby->hasUrl());
        $this->assertEquals('http://example.com/api/help/resource', $describedby->getUrl());

        $children = $links->get('children');
        $this->assertTrue($children->hasRoute());
        $this->assertEquals('resource/children', $children->getRoute());
    }

    /**
     * @group 79
     */
    public function testInjectsLinksFromMetadataWhenCreatingCollection()
    {
        $set = new TestAsset\Collection(
            [
                (object) ['id' => 'foo', 'name' => 'foo'],
                (object) ['id' => 'bar', 'name' => 'bar'],
                (object) ['id' => 'baz', 'name' => 'baz'],
            ]
        );

        $metadata = new MetadataMap([
            TestAsset\Collection::class => [
                'is_collection'  => true,
                'route'          => 'hostname/contacts',
                'resource_route' => 'hostname/embedded',
                'links'          => [
                    [
                        'rel' => 'describedby',
                        'url' => 'http://example.com/api/help/collection',
                    ],
                ],
            ],
        ]);

        $this->plugin->setMetadataMap($metadata);

        $collection = $this->plugin->createCollectionFromMetadata(
            $set,
            $metadata->get(TestAsset\Collection::class)
        );
            $this->assertInstanceof(HalCollection::class, $collection);
            $links = $collection->getLinks();
            $this->assertTrue($links->has('describedby'));
            $link = $links->get('describedby');
            $this->assertTrue($link->hasUrl());
            $this->assertEquals('http://example.com/api/help/collection', $link->getUrl());
    }

    /**
     * @group 79
     */
    public function testRenderResourceTriggersEvent()
    {
        $resource = new HalResource(
            (object) [
                'id'   => 'user',
                'name' => 'matthew',
            ],
            'user'
        );
        $self = new Link('self');
        $self->setRoute('hostname/users', ['id' => 'user']);
        $resource->getLinks()->add($self);

        $this->plugin->getEventManager()->attach('renderResource', function ($e) {
            $resource = $e->getParam('resource');
            $resource->getLinks()->get('self')->setRouteParams(['id' => 'matthew']);
        });

        $rendered = $this->plugin->renderResource($resource);
        $this->assertContains('/users/matthew', $rendered['_links']['self']['href']);
    }

    /**
     * @group 79
     */
    public function testRenderCollectionTriggersEvent()
    {
        $collection = new HalCollection(
            [
                (object) ['id' => 'foo', 'name' => 'foo'],
                (object) ['id' => 'bar', 'name' => 'bar'],
                (object) ['id' => 'baz', 'name' => 'baz'],
            ],
            'hostname/contacts'
        );
        $self = new Link('self');
        $self->setRoute('hostname/contacts');
        $collection->getLinks()->add($self);
        $collection->setCollectionName('resources');

        $this->plugin->getEventManager()->attach('renderCollection', function ($e) {
            $collection = $e->getParam('collection');
            $collection->setAttributes(['injected' => true]);
        });

        $rendered = $this->plugin->renderCollection($collection);
        $this->assertArrayHasKey('injected', $rendered);
        $this->assertTrue($rendered['injected']);
    }

    public function matchUrl($url)
    {
        $url     = 'http://localhost.localdomain' . $url;
        $request = new Request();
        $request->setUri($url);

        $match = $this->router->match($request);
        if ($match instanceof RouteMatch) {
            $this->urlHelper->setRouteMatch($match);
        }

        return $match;
    }

    /**
     * @group 95
     */
    public function testPassingFalseReuseParamsOptionShouldOmitMatchedParametersInGeneratedLink()
    {
        $matches = $this->matchUrl('/resource/foo');
        $this->assertEquals('foo', $matches->getParam('id', false));

        $link = Link::factory([
            'rel' => 'resource',
            'route' => [
                'name' => 'hostname/resource',
                'options' => [
                    'reuse_matched_params' => false,
                ],
            ],
        ]);
        $result = $this->plugin->fromLink($link);
        $expected = [
            'href' => 'http://localhost.localdomain/resource',
        ];
        $this->assertEquals($expected, $result);
    }
}
