<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Webhooks;

use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\NotFoundException;
use Flashpi\Laravel\Events\AccountEmailPaused;
use Flashpi\Laravel\Events\AccountWebhookEvent;
use Flashpi\Laravel\Events\CertificateWebhookEvent;
use Flashpi\Laravel\Events\DocumentEmailBounced;
use Flashpi\Laravel\Events\DocumentWebhookEvent;
use Flashpi\Laravel\Events\InvoiceAuthorized;
use Flashpi\Laravel\Events\WebhookEvent;
use Flashpi\Laravel\Events\WebhookEvents;
use Flashpi\Laravel\Events\WebhookReceived;
use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Laravel\Tests\Support\Webhook;
use Flashpi\Laravel\Tests\TestCase;
use Flashpi\Types\ApiTypes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The event classes: one per webhook type of the SDK, built from a webhook's body, serializable,
 * and able to fetch the event of a thin payload.
 */
final class WebhookEventsTest extends TestCase
{
    /** The base class of each of the SDK's lists of event types. */
    private const CATEGORIES = [
        'DocumentEventType' => DocumentWebhookEvent::class,
        'CertificateEventType' => CertificateWebhookEvent::class,
        'AccountEventType' => AccountWebhookEvent::class,
    ];

    public function testHasAClassForEveryWebhookTypeOfTheSdk(): void
    {
        $types = [];
        foreach (array_keys(self::CATEGORIES) as $alias) {
            $types = [...$types, ...self::sdkTypes($alias)];
        }
        sort($types);
        $known = array_keys(WebhookEvents::CLASSES);
        sort($known);

        // A type the SDK adds must get its class here (and one it drops, lose it).
        self::assertSame($types, $known);
        self::assertCount(26, $known);
        // Thin payloads carry the same types.
        $thin = self::sdkTypes('EventThinType');
        sort($thin);
        self::assertSame($types, $thin);
    }

    public function testNamesEachClassAfterItsTypeAndItsCategory(): void
    {
        foreach (self::CATEGORIES as $alias => $base) {
            foreach (self::sdkTypes($alias) as $type) {
                $class = WebhookEvents::classFor($type);
                self::assertNotNull($class, $type);
                $studly = implode('', array_map(ucfirst(...), preg_split('/[._]/', $type) ?: []));
                self::assertSame("Flashpi\\Laravel\\Events\\{$studly}", $class);
                self::assertSame($type, $class::TYPE);
                self::assertSame($base, get_parent_class($class), $class);
                self::assertTrue((new \ReflectionClass($class))->isFinal(), $class);
            }
        }
        self::assertSame(DocumentEmailBounced::class, WebhookEvents::classFor('document.email.bounced'));
        self::assertSame(AccountEmailPaused::class, WebhookEvents::classFor('account.email_paused'));
        self::assertNull(WebhookEvents::classFor('webhook_endpoint.verification'));
        self::assertNull(WebhookEvents::classFor('invoice.archived'));
    }

    public function testHasNoOtherEventClass(): void
    {
        $classes = [];
        foreach (glob(\dirname(__DIR__, 2) . '/src/Events/*.php') ?: [] as $file) {
            $classes[] = 'Flashpi\\Laravel\\Events\\' . basename($file, '.php');
        }
        $expected = [
            ...array_values(WebhookEvents::CLASSES),
            ...array_values(self::CATEGORIES),
            WebhookEvent::class,
            WebhookEvents::class,
            WebhookReceived::class,
        ];
        sort($classes);
        sort($expected);

        self::assertSame($expected, $classes);
    }

    public function testReadsAFullPayload(): void
    {
        $payload = Webhook::event('invoice.authorized');

        $event = new InvoiceAuthorized($payload, 'delivery-1');

        self::assertSame(Webhook::ID, $event->id);
        self::assertSame('invoice.authorized', $event->type);
        self::assertSame('test', $event->environment);
        self::assertSame('2026-11-16T15:20:00.000Z', $event->createdAt);
        self::assertSame(Webhook::document(), $event->data);
        self::assertSame($payload, $event->payload);
        self::assertSame('delivery-1', $event->webhookId);
        self::assertFalse($event->isThin());
    }

    public function testReadsAThinPayload(): void
    {
        $event = new InvoiceAuthorized(Webhook::thin('invoice.authorized', 'live'));

        self::assertTrue($event->isThin());
        self::assertNull($event->data);
        self::assertSame('live', $event->environment);
        // The webhook-id is the event's id unless given.
        self::assertSame(Webhook::ID, $event->webhookId);
    }

    public function testWebhookReceivedTakesAnyType(): void
    {
        $event = new WebhookReceived(Webhook::event('invoice.archived', ['id' => 'doc_1']));

        self::assertSame('invoice.archived', $event->type);
        self::assertSame(['id' => 'doc_1'], $event->data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badPayloads(): iterable
    {
        $event = Webhook::event('invoice.authorized');

        yield 'another type' => [Webhook::event('invoice.rejected'), 'Flashpi\\Laravel\\Events\\InvoiceAuthorized is the invoice.authorized event, not invoice.rejected'];
        yield 'no id' => [array_diff_key($event, ['id' => true]), 'The id of a Flashpi event is a string'];
        yield 'an empty id' => [['id' => '', ...array_diff_key($event, ['id' => true])], 'The id of a Flashpi event is a string'];
        yield 'no type' => [array_diff_key($event, ['type' => true]), 'The type of a Flashpi event is a string'];
        yield 'a type that is not a string' => [['type' => 1, ...array_diff_key($event, ['type' => true])], 'The type of a Flashpi event is a string'];
        yield 'no environment' => [array_diff_key($event, ['environment' => true]), 'A Flashpi event has the environment test or live'];
        yield 'another environment' => [Webhook::event('invoice.authorized', environment: 'staging'), 'A Flashpi event has the environment test or live'];
        yield 'no date' => [array_diff_key($event, ['createdAt' => true]), 'The createdAt of a Flashpi event is a string'];
        yield 'null data' => [['data' => null, ...array_diff_key($event, ['data' => true])], 'The data of a Flashpi event is an object'];
        yield 'text data' => [['data' => 'x', ...array_diff_key($event, ['data' => true])], 'The data of a Flashpi event is an object'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('badPayloads')]
    public function testRefusesABodyThatIsNotAnEventOfItsType(array $payload, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new InvoiceAuthorized($payload);
    }

    public function testSurvivesSerialization(): void
    {
        Http::fake([Api::url('/v1/events/' . Webhook::ID) => Api::json(Webhook::event('invoice.authorized'))]);
        $fetched = new InvoiceAuthorized(Webhook::thin('invoice.authorized'));
        $fetched->fetch();
        $events = [
            new InvoiceAuthorized(Webhook::event('invoice.authorized'), 'delivery-1'),
            new InvoiceAuthorized(Webhook::thin('invoice.authorized')),
            new WebhookReceived(Webhook::event('invoice.archived', ['id' => 'doc_1'])),
            $fetched,
        ];

        foreach ($events as $event) {
            $copy = unserialize(serialize($event));
            self::assertInstanceOf($event::class, $copy);
            self::assertEquals($event, $copy);
            self::assertNotSame($event, $copy);
        }
        // What was fetched comes along: the copy asks the API nothing.
        self::assertInstanceOf(InvoiceAuthorized::class, $copy);
        self::assertSame(Webhook::event('invoice.authorized'), $copy->fetch());
        Http::assertSentCount(1);
    }

    public function testFetchesTheEventOfAThinPayloadOnce(): void
    {
        $full = Webhook::event('invoice.authorized');
        Http::fake([Api::url('/v1/events/' . Webhook::ID) => Api::json($full)]);
        $event = new InvoiceAuthorized(Webhook::thin('invoice.authorized'));

        foreach (['first', 'second'] as $call) {
            self::assertSame($full, $event->fetch(), "The {$call} call");
        }

        Http::assertSentCount(1);
        Http::assertSent(fn(Request $request): bool => $request->method() === 'GET'
            && $request->url() === Api::url('/v1/events/' . Webhook::ID)
            && $request->header('Authorization') === ["Bearer {$this->apiKey}"]);
        // The payload stays thin: fetch() is the way to the data.
        self::assertTrue($event->isThin());
    }

    public function testFetchesAnEventOfATypeThisVersionDoesNotKnow(): void
    {
        $full = [...Webhook::event('invoice.archived', ['id' => 'doc_1']), 'reason' => 'new field'];
        Http::fake([Api::url('/v1/events/' . Webhook::ID) => Api::json($full)]);

        self::assertEquals($full, (new WebhookReceived(Webhook::thin('invoice.archived')))->fetch());
    }

    public function testRefusesToFetchWithAKeyOfTheOtherEnvironment(): void
    {
        Http::fake();
        $event = new InvoiceAuthorized(Webhook::thin('invoice.authorized', 'live'));

        try {
            $event->fetch();
            self::fail('No exception');
        } catch (FlashpiException $error) {
            self::assertSame(
                'The event ' . Webhook::ID . ' is from the live environment, but FLASHPI_API_KEY is a test key: fetch it with a key of the live environment',
                $error->getMessage(),
            );
        }
        Http::assertNothingSent();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function otherEvents(): iterable
    {
        $event = Webhook::event('invoice.authorized');

        yield 'another id' => [['id' => 'other', ...array_diff_key($event, ['id' => true])]];
        yield 'another type' => [Webhook::event('invoice.rejected')];
        yield 'another environment' => [Webhook::event('invoice.authorized', environment: 'live')];
        yield 'no date' => [array_diff_key($event, ['createdAt' => true])];
        yield 'thin' => [Webhook::thin('invoice.authorized')];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('otherEvents')]
    public function testRefusesAnAnswerThatIsNotTheEventWithItsData(array $answer): void
    {
        Http::fake([Api::url('/v1/events/' . Webhook::ID) => Api::json($answer)]);

        $this->expectException(FlashpiException::class);
        $this->expectExceptionMessage('The API did not return the event ' . Webhook::ID . ' with its data');

        (new InvoiceAuthorized(Webhook::thin('invoice.authorized')))->fetch();
    }

    public function testLetsTheApisErrorsThrough(): void
    {
        Http::fake([Api::url('/v1/events/' . Webhook::ID) => Api::problem(404, 'not_found')]);

        $this->expectException(NotFoundException::class);

        (new InvoiceAuthorized(Webhook::thin('invoice.authorized')))->fetch();
    }

    public function testFetchesWithTheClientOfTheContainer(): void
    {
        $this->config()->set('flashpi.api_key', null);
        $event = new InvoiceAuthorized(Webhook::thin('invoice.authorized'));

        $this->expectException(FlashpiException::class);
        $this->expectExceptionMessage('Missing Flashpi API key: set FLASHPI_API_KEY');

        $event->fetch();
    }

    /**
     * The values of one of the SDK's string unions (ApiTypes), from its PHPDoc.
     *
     * @return list<string>
     */
    private static function sdkTypes(string $alias): array
    {
        $doc = (string) (new \ReflectionClass(ApiTypes::class))->getDocComment();
        self::assertSame(1, preg_match("/@phpstan-type {$alias} (.+?)(?=\\n \\* @phpstan-type )/s", $doc, $match), $alias);
        preg_match_all("/'([a-z_.]+)'/", $match[1] ?? '', $values);
        self::assertNotEmpty($values[1], $alias);

        return $values[1];
    }
}
