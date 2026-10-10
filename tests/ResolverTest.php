<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Closure;
use Eleph\Runtime\Policy\AccessDenied;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\QueryFieldEntry;
use Eleph\WPGraphQL\Registration\MutationRegistrar;
use Eleph\WPGraphQL\Registration\TypeRegistrar;
use Eleph\WPGraphQL\Relay\GlobalId;
use Eleph\WPGraphQL\Resolver\Connections;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use stdClass;

/**
 * The layer's job is dispatch: given a field, call the right thing with the right
 * arguments. What the runtime then does is tested where the runtime is.
 */
#[CoversClass(TypeRegistrar::class)]
#[CoversClass(MutationRegistrar::class)]
#[CoversClass(Connections::class)]
final class ResolverTest extends TestCase
{
    public function testARootFieldFetchesById(): void
    {
        $gateway = new FakeGateway();
        $resolve = $this->rootField($gateway, 'post')['resolve'];

        self::assertIsCallable($resolve);
        $resolve(null, ['id' => '7']);

        self::assertSame(['find Post#7'], $gateway->calls);
    }

    public function testARootFieldWithoutAUsableIdResolvesToNullRatherThanFailing(): void
    {
        $gateway = new FakeGateway();
        $resolve = $this->rootField($gateway, 'post')['resolve'];

        self::assertIsCallable($resolve);
        self::assertNull($resolve(null, []));
        self::assertSame([], $gateway->calls);
    }

    public function testWrongTypeIdsNeverReachTheGateway(): void
    {
        $gateway = new FakeGateway();
        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();
        $root = $this->rootField($gateway, 'post')['resolve'];
        self::assertIsCallable($root);
        $wrong = GlobalId::encode('Author', 7);
        $calls = [
            static fn () => $root(null, ['id' => $wrong]),
            fn () => $this->call($configs['deletePost']['mutateAndGetPayload'], ['id' => $wrong]),
            fn () => $this->call($configs['updatePost']['mutateAndGetPayload'], ['id' => $wrong]),
            fn () => $this->call($configs['publishPost']['mutateAndGetPayload'], ['id' => $wrong]),
            fn () => $this->call($configs['createComment']['mutateAndGetPayload'], ['author' => GlobalId::encode('Post', 7)]),
            fn () => $this->call($configs['createPost']['mutateAndGetPayload'], ['tags' => [GlobalId::encode('Tag', 1), $wrong]]),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('A wrong-type ID was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Expected a', $exception->getMessage());
            }
        }
        self::assertSame([], $gateway->calls);
    }

    public function testMatchingRelationshipIdsAreUnwrapped(): void
    {
        $gateway = new FakeGateway();
        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();
        $this->call($configs['createComment']['mutateAndGetPayload'], ['author' => GlobalId::encode('Author', 7)]);
        self::assertSame(['author' => '7'], $gateway->input);
        $this->call($configs['updatePost']['mutateAndGetPayload'], ['id' => GlobalId::encode('Post', 1), 'tags' => [GlobalId::encode('Tag', 2), '3']]);
        self::assertSame(['tags' => ['2', '3']], $gateway->input);
    }

    public function testAccessDeniedPropagatesFromTheRuntimeAsAnException(): void
    {
        $gateway = new FakeGateway();
        $gateway->denyReads = true;
        $resolve = $this->rootField($gateway, 'post')['resolve'];

        $this->expectException(AccessDenied::class);
        self::assertIsCallable($resolve);
        $resolve(null, ['id' => '7']);
    }

    public function testARootConnectionAsksForEverythingAndShapesAPage(): void
    {
        $gateway = new FakeGateway();
        $gateway->items = [new stdClass(), new stdClass()];
        $gateway->total = 347;
        $gateway->hasMore = true;

        $page = $this->call($this->connection($gateway, 'posts')['resolve'], ['first' => 2]);

        self::assertSame(['all Post'], $gateway->calls);
        self::assertIsArray($page['nodes']);
        self::assertIsArray($page['edges']);
        self::assertIsArray($page['pageInfo']);
        self::assertCount(2, $page['nodes']);
        self::assertCount(2, $page['edges']);
        self::assertSame(347, $page['totalCount'], 'the table needs a total, not a page size');
        self::assertTrue($page['pageInfo']['hasNextPage']);
        self::assertFalse($page['pageInfo']['hasPreviousPage']);
    }

    public function testSingularQueriesPreserveFoundAbsentAndDeniedResults(): void
    {
        $manifest = new Manifest(queries: ['postBySlug' => new QueryFieldEntry('postBySlug', 'Post', false, 'Post', 'bySlug')]);
        $gateway = new FakeGateway();
        $gateway->singularQuery = true;
        $resolve = (new TypeRegistrar($manifest, $gateway))->rootFieldConfigs()[0]['field']['resolve'];
        self::assertIsCallable($resolve);
        $post = new stdClass();
        $gateway->items = [$post];
        self::assertSame($post, $resolve(null, []));
        $gateway->items = [];
        self::assertNull($resolve(null, []));
        $gateway->items = [$post];
        $gateway->denyReads = true;
        self::assertNull($resolve(null, []));
    }

    public function testAPublishedQueryResolvesThroughItsOwnName(): void
    {
        $gateway = new FakeGateway();
        $this->call($this->connection($gateway, 'publishedPosts')['resolve'], ['first' => 5, 'limit' => 10]);

        self::assertSame(['query Post::published(first,limit)'], $gateway->calls);
    }

    public function testAnOversizedPageIsCapped(): void
    {
        // `first: 1000000` is how a connection stops being a connection.
        $gateway = new FakeGateway();
        $gateway->items = array_fill(0, 300, new stdClass());

        $page = $this->call($this->connection($gateway, 'posts')['resolve'], ['first' => 1_000_000]);

        self::assertIsArray($page['nodes']);
        self::assertCount(100, $page['nodes']);
    }

    public function testAZeroPageIsEmptyAndReportsVisibleRowsRemain(): void
    {
        $gateway = new FakeGateway();
        $gateway->items = [new stdClass()];
        $gateway->total = 1;
        $page = $this->call($this->connection($gateway, 'posts')['resolve'], ['first' => 0]);
        self::assertSame([], $page['nodes']);
        self::assertSame([], $page['edges']);
        self::assertIsArray($page['pageInfo']);
        self::assertTrue($page['pageInfo']['hasNextPage']);
        self::assertNull($page['pageInfo']['endCursor']);
    }

    public function testUnsupportedAndNegativePaginationIsRejected(): void
    {
        foreach ([['last' => 1], ['before' => 'cursor'], ['first' => -1]] as $args) {
            try {
                $this->call($this->connection(new FakeGateway(), 'posts')['resolve'], $args);
                self::fail('Invalid pagination was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testCreateUpdateAndActionEachReachTheirOwnCall(): void
    {
        $gateway = new FakeGateway();
        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();

        $this->call($configs['createPost']['mutateAndGetPayload'], ['title' => 'Hello']);
        $this->call($configs['updatePost']['mutateAndGetPayload'], ['id' => '7', 'title' => 'New']);
        $this->call($configs['publishPost']['mutateAndGetPayload'], ['id' => '7', 'at' => null]);

        self::assertSame([
            'create Post(title)',
            'update Post#7(title)',
            'action Post::publish#7(at)',
        ], $gateway->calls);
    }

    public function testDeleteIsRegisteredEvenThoughTheManifestHasNoEntryForIt(): void
    {
        // It takes nothing beyond an id, so there is nothing for the manifest builder
        // to derive from the spec — but the API still needs it.
        $gateway = new FakeGateway();
        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();

        self::assertArrayHasKey('deletePost', $configs);

        $payload = $this->call($configs['deletePost']['mutateAndGetPayload'], ['id' => '7']);

        self::assertSame(['delete Post#7'], $gateway->calls);

        // The global form, because it is the id the client cached under and so the one
        // it has to evict. NodeTest covers the round trip.
        self::assertSame(GlobalId::encode('Post', 7), $payload['deletedId']);
    }

    public function testAHiddenMutationResultIsNullWithoutAReadError(): void
    {
        $gateway = new FakeGateway();
        $gateway->denyReads = true;
        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();
        $payload = $this->call($configs['updatePost']['mutateAndGetPayload'], ['id' => '7']);
        $outputs = $configs['updatePost']['outputFields'];
        self::assertIsArray($outputs);
        $output = $outputs['post'];
        self::assertIsArray($output);
        self::assertIsCallable($output['resolve']);
        self::assertNull($output['resolve']($payload));
        self::assertSame(['update Post#7()'], $gateway->calls);
    }

    public function testAMutationHandsBackTheRowItTouched(): void
    {
        // So a client can update its cache without a second round trip.
        $gateway = new FakeGateway();
        $gateway->items = [new stdClass()];

        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();

        $outputs = $configs['updatePost']['outputFields'];
        self::assertIsArray($outputs);

        $output = $outputs['post'];
        self::assertIsArray($output);

        self::assertSame('Post', $output['type']);
        self::assertIsCallable($output['resolve']);
        self::assertNotNull($output['resolve']($this->call($configs['updatePost']['mutateAndGetPayload'], ['id' => '7'])));
    }

    /**
     * Invoke a resolver and narrow what it hands back.
     *
     * The configs are `array<string, mixed>` because that is what WPGraphQL takes, so
     * every call site would otherwise need the same two assertions.
     *
     * @param array<array-key, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function call(mixed $resolve, array $args): array
    {
        self::assertIsCallable($resolve);

        $result = 1 === (new ReflectionFunction(Closure::fromCallable($resolve)))->getNumberOfParameters()
            ? $resolve($args)
            : $resolve(null, $args);

        self::assertIsArray($result);

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function rootField(FakeGateway $gateway, string $name): array
    {
        foreach ((new TypeRegistrar($this->manifest(), $gateway))->rootFieldConfigs() as $config) {
            if ($name === $config['name']) {
                /** @var array<string, mixed> $field */
                $field = $config['field'];

                return $field;
            }
        }

        self::fail(sprintf('No root field "%s".', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(FakeGateway $gateway, string $field, string $from = 'RootQuery'): array
    {
        foreach ((new TypeRegistrar($this->manifest(), $gateway))->connectionConfigs() as $config) {
            // Qualified by the type it hangs off: an inverse can give an entity a
            // connection of the same name as a root one.
            if ($field === $config['fromFieldName'] && $from === $config['fromType']) {
                return $config;
            }
        }

        self::fail(sprintf('No connection "%s" on %s.', $field, $from));
    }

    private function manifest(): Manifest
    {
        return Fixture::manifest();
    }
}
