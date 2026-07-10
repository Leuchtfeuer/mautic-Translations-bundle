<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\StandAloneButtonType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AuthConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('deepl_api_key', PasswordType::class, [
            'label'      => 'plugin.leuchtfeuertranslations.deepl_api_key',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'attr'       => ['class' => 'form-control', 'placeholder' => '**************', 'autocomplete' => 'off'],
        ]);

        $builder->add('test_connection_button', StandAloneButtonType::class, [
            'label'    => 'plugin.leuchtfeuertranslations.test_api_connection',
            'required' => false,
            'attr'     => [
                'class'   => 'btn btn-tertiary btn-sm',
                'onclick' => 'LFTranslations.testApiConnection(this)',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['integration']);
    }
}
