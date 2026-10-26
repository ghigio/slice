<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/** Nome del gruppo da aggiungere dal link d'invito; le regole sul nome sono in EveningAdministration. */
final class InviteEaterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome del gruppo',
                'required' => false,
                'help' => 'Il nome della tua famiglia o del tuo gruppo. Dopo l\'aggiunta salva il link della pagina del gruppo.',
                'attr' => ['maxlength' => 255, 'autocomplete' => 'off'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Aggiungi il mio gruppo',
                'attr' => ['class' => 'btn btn-primary btn-lg w-100'],
            ]);
    }
}
