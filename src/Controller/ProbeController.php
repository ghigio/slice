<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SONDA TEMPORANEA per le verifiche SQLite su fortrabbit (fase 1). Da rimuovere dopo l'esito.
 */
final class ProbeController
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    #[Route('/_verifica-a83089a51f6a18567e9c3e93', name: 'probe', methods: ['GET'])]
    public function __invoke(): Response
    {
        $righe = ['PHP: '.PHP_VERSION, 'pdo_sqlite: '.(extension_loaded('pdo_sqlite') ? 'attiva' : 'NON attiva')];

        if (extension_loaded('pdo_sqlite')) {
            @mkdir($this->projectDir.'/var/data', 0775, true);
            $pdo = new \PDO('sqlite:'.$this->projectDir.'/var/data/probe.db');
            $pdo->exec('CREATE TABLE IF NOT EXISTS visite (creata_il TEXT NOT NULL)');
            $pdo->exec("INSERT INTO visite (creata_il) VALUES (datetime('now'))");
            foreach ($pdo->query('SELECT creata_il FROM visite ORDER BY rowid') as $riga) {
                $righe[] = 'visita: '.$riga['creata_il'].' UTC';
            }
        }

        return new Response(implode("\n", $righe), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
