<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Events\EventBus — dispatch + persistence to events table.
 *
 * Verifies:
 *   - subscribe() + dispatch() invokes the subscriber.
 *   - dispatch() persists the event to the `events` table (so SSE can replay).
 *   - eventsSince(lastId) returns the persisted events in order.
 *   - lastEventId() returns the max ID.
 *   - Wildcard '*' subscribers receive all events.
 *   - Subscriber exceptions are caught (don't crash dispatch).
 */
final class EventBusTest extends TestCase
{
    public function name(): string
    {
        return 'EventBusTest';
    }

    public function run(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/eventbus-test.log', 'debug');

        // Build a real Connection via the Container (so we get the same
        // memoized singleton the app uses, with all 8 ctor params filled
        // from the Environment).
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $connection = $container->make(\App\Shared\Persistence\Connection::class);

        // Clean the events table so the test is deterministic.
        $connection->execute('DELETE FROM events');
        // Note: AUTO_INCREMENT doesn't reset on DELETE, so IDs won't start at 1.
        // We use lastEventId() AFTER dispatch to capture the actual ID.
        $bus = new \App\Events\EventBus($logger, $connection);

        // 1. Dispatch with no subscribers — should still persist.
        $bus->dispatch(new \App\Events\Events\SearchRequested(['query' => 'test-1']));
        $firstId = $bus->lastEventId();
        $this->assertGreaterThanOrEqual(1, $firstId, 'lastEventId after first dispatch');

        // 2. Subscribe a recording subscriber + a wildcard log subscriber.
        $recorded = [];
        $bus->on(\App\Events\Events\SearchCompleted::TYPE, function ($event) use (&$recorded) {
            $recorded[] = $event->payload();
        });

        $wildcardHits = 0;
        $bus->on('*', function ($event) use (&$wildcardHits) {
            $wildcardHits++;
        });

        // 3. Dispatch SearchCompleted — should invoke both subscribers + persist.
        $bus->dispatch(new \App\Events\Events\SearchCompleted(['total' => 42, 'page' => 1]));
        $this->assertCount(1, $recorded, 'typed subscriber received event');
        $this->assertSame(42, $recorded[0]['total'], 'subscriber payload total');
        $this->assertSame(1, $wildcardHits, 'wildcard subscriber hit once');

        // 4. eventsSince(firstId - 1) should return both events of this test in order.
        $events = $bus->eventsSince($firstId - 1, 10);
        $this->assertCount(2, $events, 'eventsSince returns 2 persisted events');
        $this->assertSame('search_requested', $events[0]['type'], 'first event type');
        $this->assertSame('search_completed', $events[1]['type'], 'second event type');
        $this->assertSame('test-1', $events[0]['payload']['query'], 'first event payload');
        $this->assertSame(42, $events[1]['payload']['total'], 'second event payload');

        // 5. eventsSince(lastId - 1) should return only the most recent.
        $lastId = $bus->lastEventId();
        $recent = $bus->eventsSince($lastId - 1, 10);
        $this->assertCount(1, $recent, 'eventsSince(lastId-1) returns 1 event');
        $this->assertSame('search_completed', $recent[0]['type'], 'most recent event type');

        // 6. Subscriber exception is caught — dispatch continues to persist.
        $bus->on(\App\Events\Events\LicenseSaved::TYPE, function ($event) {
            throw new \RuntimeException('subscriber boom');
        });
        $bus->dispatch(new \App\Events\Events\LicenseSaved(['rn' => 'L-99']));
        $this->assertSame($firstId + 2, $bus->lastEventId(), 'lastEventId after exception-throwing subscriber');

        // 7. subscriberCount helper.
        $this->assertGreaterThanOrEqual(1, $bus->subscriberCount('search_completed'), 'subscriberCount typed');
        $this->assertGreaterThanOrEqual(1, $bus->subscriberCount('*'), 'subscriberCount wildcard');

        // Clean up.
        $connection->execute('DELETE FROM events');
    }
}

return new EventBusTest();
