<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class LeuchtfeuerTranslationsIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME         = 'LeuchtfeuerTranslations';

    public const DISPLAY_NAME = 'Translations by Leuchtfeuer';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getIcon(): string
    {
        return 'plugins/LeuchtfeuerTranslationsBundle/Assets/img/icon.png';
    }
}
