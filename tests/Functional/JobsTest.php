<?php

namespace App\Tests\Functional;

use App\Entity\Evening;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * JOBS_SECRET e cartella dei backup di test sono definiti in .env.test e config/services.yaml (when@test).
 */
final class JobsTest extends WebTestCase
{
    private const string SECRET = 'segreto-di-test-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/test-backups');
    }

    public function testEndpointEseguePuliziaEBackup(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Evening('Vecchia', (new \DateTimeImmutable('today'))->modify('-120 days'), 'Org'));
        $em->flush();

        $client->request('GET', '/_jobs/run/'.self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        $body = $client->getResponse()->getContent();
        self::assertStringContainsString('Serate cancellate: 1', $body);
        self::assertStringContainsString('Backup: creato app-', $body);
        self::assertStringContainsString('IP del client: 127.0.0.1', $body);

        $client->request('GET', '/_jobs/run/'.self::SECRET);
        self::assertStringContainsString('Backup: già eseguito oggi', $client->getResponse()->getContent());
    }

    public function testSegretoErratoDa404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_jobs/run/sbagliato');

        self::assertResponseStatusCodeSame(404);
    }

    public function testComandiConsole(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $purge = new CommandTester($application->find('app:evenings:purge'));
        $purge->execute([]);
        $purge->assertCommandIsSuccessful();
        self::assertStringContainsString('Serate cancellate: 0', $purge->getDisplay());

        $backup = new CommandTester($application->find('app:db:backup'));
        $backup->execute([]);
        $backup->assertCommandIsSuccessful();
        self::assertStringContainsString('Backup: creato app-', $backup->getDisplay());
    }
}
