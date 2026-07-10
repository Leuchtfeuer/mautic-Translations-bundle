<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Form\Type\AuthConfigType;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration;

class ConfigSupport extends LeuchtfeuerTranslationsIntegration implements ConfigFormInterface, ConfigFormAuthInterface
{
    use DefaultConfigFormTrait;

    public function getAuthConfigFormName(): string
    {
        return AuthConfigType::class;
    }
}
