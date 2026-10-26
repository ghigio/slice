<?php

namespace App\Evening;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Enum\EveningStatus;
use App\Evening\Exception\EveningRuleException;
use App\Text\NameNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Operazioni dell'organizzatore su gruppi e stato della serata, con tutte le verifiche lato server.
 * Gli id dei gruppi arrivano dal client: vengono sempre cercati tra i gruppi della serata indicata.
 */
final class EveningAdministration
{
    public const int MAX_EATERS = 50;
    private const int MAX_NAME_LENGTH = 255;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function addEater(Evening $evening, string $name): Eater
    {
        $this->assertEditable($evening);
        if ($evening->getEaters()->count() >= self::MAX_EATERS) {
            throw new EveningRuleException(sprintf('Una serata può avere al massimo %d gruppi.', self::MAX_EATERS));
        }
        $name = $this->validName($evening, $name, null);

        $eater = new Eater($evening, $name);
        $this->em->persist($eater);
        $this->em->flush();

        return $eater;
    }

    /** Aggiunta di un gruppo dal link d'invito: consentita solo con ordini aperti. */
    public function addEaterFromInvite(Evening $evening, string $name): Eater
    {
        if (EveningStatus::OrdersOpen !== $evening->getStatus()) {
            throw new EveningRuleException('Non è più possibile aggiungere gruppi: gli ordini sono chiusi.');
        }

        return $this->addEater($evening, $name);
    }

    public function regenerateInviteToken(Evening $evening): void
    {
        $this->assertEditable($evening);
        $evening->regenerateInviteToken();
        $this->em->flush();
    }

    public function renameEater(Evening $evening, int $eaterId, string $name): void
    {
        $this->assertEditable($evening);
        $eater = $this->eater($evening, $eaterId);
        $eater->setName($this->validName($evening, $name, $eater));
        $this->em->flush();
    }

    public function deleteEater(Evening $evening, int $eaterId, bool $confirmed): void
    {
        $this->assertEditable($evening);
        $eater = $this->eater($evening, $eaterId);
        if (!$confirmed && !$eater->getPizzas()->isEmpty()) {
            throw new EveningRuleException("Il gruppo ha delle pizze: conferma l'eliminazione.");
        }

        // Le pizze vengono cancellate dal database (ON DELETE CASCADE).
        $evening->removeEater($eater);
        $this->em->remove($eater);
        $this->em->flush();
    }

    public function setOrganizerEater(Evening $evening, ?int $eaterId): void
    {
        $this->assertEditable($evening);
        $evening->setOrganizerEater(null === $eaterId ? null : $this->eater($evening, $eaterId));
        $this->em->flush();
    }

    public function regenerateEaterToken(Evening $evening, int $eaterId): Eater
    {
        $this->assertEditable($evening);
        $eater = $this->eater($evening, $eaterId);
        $eater->regenerateToken();
        $this->em->flush();

        return $eater;
    }

    public function closeOrders(Evening $evening): void
    {
        $evening->closeOrders();
        $this->em->flush();
    }

    public function reopenOrders(Evening $evening): void
    {
        $evening->reopenOrders();
        $this->em->flush();
    }

    public function archive(Evening $evening): void
    {
        $evening->archive();
        $this->em->flush();
    }

    public function unarchive(Evening $evening): void
    {
        $evening->unarchive();
        $this->em->flush();
    }

    private function assertEditable(Evening $evening): void
    {
        if (!$evening->canManageEaters()) {
            throw new EveningRuleException('La serata è archiviata: riaprila per modificarla.');
        }
    }

    private function eater(Evening $evening, int $eaterId): Eater
    {
        foreach ($evening->getEaters() as $eater) {
            if ($eater->getId() === $eaterId) {
                return $eater;
            }
        }

        throw new EveningRuleException('Gruppo non trovato.');
    }

    /** Nome ripulito, obbligatorio e unico nella serata senza distinzione di maiuscole, accenti e spazi. */
    private function validName(Evening $evening, string $name, ?Eater $current): string
    {
        $name = NameNormalizer::clean($name);
        if ('' === $name) {
            throw new EveningRuleException('Il nome del gruppo è obbligatorio.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new EveningRuleException(sprintf('Il nome del gruppo può avere al massimo %d caratteri.', self::MAX_NAME_LENGTH));
        }

        $key = NameNormalizer::normalize($name);
        foreach ($evening->getEaters() as $eater) {
            if ($eater !== $current && NameNormalizer::normalize($eater->getName()) === $key) {
                throw new EveningRuleException('Esiste già un gruppo con questo nome.');
            }
        }

        return $name;
    }
}
