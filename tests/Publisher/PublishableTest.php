<?php

namespace RabbitEvents\Tests\Publisher;

use Illuminate\Container\Container;
use Mockery\Exception\InvalidCountException;
use RabbitEvents\Publisher\Publisher;
use RabbitEvents\Publisher\ShouldPublish;
use RabbitEvents\Publisher\Support\Publishable;
use RabbitEvents\Publisher\Support\PublishableEventTesting;

class PublishableTest extends TestCase
{
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = \Mockery::spy(Publisher::class);
        Container::getInstance()->instance(Publisher::class, $this->publisher);
    }

    public function testPublishingMethod(): void
    {
        $payload = [
            "key1" => 'value1',
            "key2" => 'value2',
        ];

        self::assertNull(Listener::publish($payload));

        $this->publisher->shouldHaveReceived()
            ->publish(\Mockery::on(function(ShouldPublish $object) use ($payload) {
                return $object instanceof Listener
                    && $object->publishEventKey() == 'something.happened'
                    && $object->toPublish() == $payload;
            }))
            ->once();
    }

    public function testFake(): void
    {
        Listener::fake();

        $payload = [
            "key1" => 'value1',
            "key2" => 'value2',
        ];

        self::assertIsString(Listener::publish($payload));

        Listener::assertPublished('something.happened', $payload);
    }

    public function testFakeAssertWithoutPayload(): void
    {
        Listener::fake();

        self::assertIsString(Listener::publish(['whatever' => 1]));

        Listener::assertPublished('something.happened');
    }

    public function testFakeAssertionFailed(): void
    {
        $this->expectException(InvalidCountException::class);

        Listener::fake();

        $payload = [
            "key1" => 'value1',
            "key2" => 'value2',
        ];

        self::assertIsString(Listener::publish($payload));

        Listener::assertPublished('something.other.happened', []);
    }

    public function testAssertNotPublishedIfNotPublished(): void
    {
        Listener::fake();

        self::assertIsString(AnotherListener::publish());

        Listener::assertNotPublished();
    }

    public function testAssertNotPublishedIfPublished(): void
    {
        $this->expectException(InvalidCountException::class);

        Listener::fake();

        Listener::publish([]);

        Listener::assertNotPublished();
    }

    public function testAssertPublishedWithoutArguments(): void
    {
        Listener::fake();

        Listener::publish(['id' => 123]);

        Listener::assertPublished();
    }

    public function testAssertPublishedWithClosure(): void
    {
        Listener::fake();

        Listener::publish(['id' => 456, 'status' => 'active']);

        Listener::assertPublished(function ($event, $payload) {
            return $payload['id'] === 456 && $payload['status'] === 'active';
        });
    }

    public function testAssertPublishedTimes(): void
    {
        Listener::fake();

        Listener::publish(['id' => 1]);
        Listener::publish(['id' => 2]);

        Listener::assertPublishedTimes(2);
    }

    public function testAssertNotPublishedWithClosure(): void
    {
        Listener::fake();

        Listener::publish(['id' => 10]);

        Listener::assertNotPublished(function ($event, $payload) {
            return $payload['id'] === 999;
        });
    }

    public function testAssertNothingPublished(): void
    {
        Listener::fake();

        Listener::assertNothingPublished();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance('db.transactions');

        parent::tearDown();
    }

    public function testFakeKeepsAfterCommitEventOfRolledBackTransactionUnpublished(): void
    {
        $transactions = new \Illuminate\Database\DatabaseTransactionsManager();
        Container::getInstance()->instance('db.transactions', $transactions);
        Listener::fake();

        $transactions->begin('default', 1);
        (new Listener(['item' => 1]))->afterCommit()->publishThroughHelper();
        $transactions->rollback('default', 0);

        Listener::assertNothingPublished();
    }

    public function testFakeRecordsAfterCommitEventOnCommit(): void
    {
        $transactions = new \Illuminate\Database\DatabaseTransactionsManager();
        Container::getInstance()->instance('db.transactions', $transactions);
        Listener::fake();

        $transactions->begin('default', 1);
        (new Listener(['item' => 1]))->afterCommit()->publishThroughHelper();
        Listener::assertNothingPublished();

        $transactions->commit('default', 1, 0);
        Listener::assertPublishedTimes(1);
    }

    public function testAssertNothingPublishedFailsWhenSomethingWasPublished(): void
    {
        Listener::fake();
        Listener::publish(['item' => 1]);

        $this->expectException(\Mockery\Exception\InvalidCountException::class);

        Listener::assertNothingPublished();
    }
}

class Listener implements ShouldPublish
{
    use Publishable;
    use PublishableEventTesting;

    public function publishThroughHelper(): ?string
    {
        return publish($this);
    }

    private $payload = [];

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function publishEventKey(): string
    {
        return 'something.happened';
    }

    public function toPublish(): array
    {
        return $this->payload;
    }
}

class AnotherListener implements ShouldPublish
{
    use Publishable;
    use PublishableEventTesting;

    public function publishEventKey(): string
    {
        // TODO: Implement publishEventKey() method.
    }

    public function toPublish(): array
    {
        // TODO: Implement toPublish() method.
    }

}
