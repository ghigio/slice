<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limiti per IP: creazione serate, pagine con token (/s, /g, /i), Live Components e tentativi con token inesistenti.
 * Disattivato solo in ambiente dev (parametro app.rate_limiting), per provare l'app senza blocchi.
 */
final class RateLimitSubscriber
{
    private const string TOKEN_PATHS = '#^/(s|g|i|_jobs)/#';

    public function __construct(
        private readonly RateLimiterFactoryInterface $eveningCreationLimiter,
        private readonly RateLimiterFactoryInterface $tokenPagesLimiter,
        private readonly RateLimiterFactoryInterface $liveComponentsLimiter,
        private readonly RateLimiterFactoryInterface $unknownTokensLimiter,
        #[Autowire('%app.rate_limiting%')]
        private readonly bool $enabled = true,
    ) {
    }

    #[AsEventListener(priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $ip = $this->ip($request);

        if (preg_match(self::TOKEN_PATHS, $path)) {
            // Dopo troppi token inesistenti l'IP è bloccato su tutte le rotte con token.
            $this->assertAccepted($this->unknownTokensLimiter->create($ip)->consume(0), requireRemaining: true);
            if (!str_starts_with($path, '/_jobs/')) {
                $this->assertAccepted($this->tokenPagesLimiter->create($ip)->consume());
            }
        } elseif (str_starts_with($path, '/_components/')) {
            $this->assertAccepted($this->liveComponentsLimiter->create($ip)->consume());
        } elseif ('/nuova-serata' === $path && $request->isMethod('POST')) {
            $this->assertAccepted($this->eveningCreationLimiter->create($ip)->consume());
        }
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        if ($this->enabled && $event->isMainRequest() && 404 === $event->getResponse()->getStatusCode() && preg_match(self::TOKEN_PATHS, $event->getRequest()->getPathInfo())) {
            $this->unknownTokensLimiter->create($this->ip($event->getRequest()))->consume();
        }
    }

    private function assertAccepted(RateLimit $limit, bool $requireRemaining = false): void
    {
        if ($limit->isAccepted() && (!$requireRemaining || $limit->getRemainingTokens() > 0)) {
            return;
        }

        throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Troppe richieste.');
    }

    private function ip(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
