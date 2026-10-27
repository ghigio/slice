<?php

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\RateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimitSubscriberTest extends TestCase
{
    public function testDisattivatoNonLimitaNulla(): void
    {
        $subscriber = $this->subscriber(enabled: false);

        for ($i = 0; $i < 3; ++$i) {
            $subscriber->onRequest($this->event('/_components/PizzaOrder'));
        }
        $this->expectNotToPerformAssertions();
    }

    public function testAttivoLimita(): void
    {
        $subscriber = $this->subscriber(enabled: true);
        $subscriber->onRequest($this->event('/_components/PizzaOrder'));

        $this->expectException(TooManyRequestsHttpException::class);
        $subscriber->onRequest($this->event('/_components/PizzaOrder'));
    }

    private function subscriber(bool $enabled): RateLimitSubscriber
    {
        $factory = fn (string $id) => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());

        return new RateLimitSubscriber($factory('creation'), $factory('pages'), $factory('live'), $factory('unknown'), $enabled);
    }

    private function event(string $path): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create($path), HttpKernelInterface::MAIN_REQUEST);
    }
}
