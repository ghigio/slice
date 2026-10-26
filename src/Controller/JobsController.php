<?php

namespace App\Controller;

use App\Jobs\JobRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Avvio dei job periodici da un URL segreto (cron esterno o a mano). Mostra anche l'IP visto dall'app,
 * per verificare la configurazione dei proxy fidati usata dal rate limiting.
 */
final class JobsController
{
    private const int MIN_SECRET_LENGTH = 32;

    public function __construct(
        private readonly JobRunner $jobs,
        #[Autowire('%env(default::JOBS_SECRET)%')] private readonly ?string $secret,
    ) {
    }

    #[Route('/_jobs/run/{secret}', name: 'jobs_run', requirements: ['secret' => '.+'], methods: ['GET'])]
    public function run(string $secret, Request $request): Response
    {
        if (null === $this->secret || \strlen($this->secret) < self::MIN_SECRET_LENGTH || !hash_equals($this->secret, $secret)) {
            throw new NotFoundHttpException();
        }

        $lines = [
            sprintf('Serate cancellate: %d', $this->jobs->purge()),
            'Backup: '.$this->jobs->backup()->describe(),
            'IP del client: '.($request->getClientIp() ?? 'sconosciuto'),
            'X-Forwarded-For: '.($request->headers->get('X-Forwarded-For') ?? '(assente)'),
        ];

        return new Response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
