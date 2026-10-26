<?php

namespace App\Form;

use App\Form\Data\NewEveningData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NewEveningType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome della serata',
                'attr' => ['placeholder' => 'Pizza da Gino', 'maxlength' => 255],
            ])
            ->add('date', DateType::class, [
                'label' => 'Data',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('pizzeria', TextType::class, [
                'label' => 'Pizzeria',
                'required' => false,
                'help' => 'Facoltativa',
                'attr' => ['maxlength' => 255],
            ])
            ->add('organizerName', TextType::class, [
                'label' => 'Il tuo nome',
                'help' => 'Sei tu a pagare il conto e a ricevere i rimborsi.',
                'attr' => ['maxlength' => 255, 'autocomplete' => 'given-name'],
            ])
            ->add('refundNotes', TextareaType::class, [
                'label' => 'Note per il rimborso',
                'required' => false,
                'help' => 'Facoltative, mostrate ai gruppi: per esempio IBAN o numero Satispay.',
                'attr' => ['rows' => 3, 'maxlength' => 1000],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Crea la serata',
                'attr' => ['class' => 'btn btn-primary btn-lg w-100'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NewEveningData::class]);
    }
}
