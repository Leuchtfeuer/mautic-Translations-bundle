<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Integration;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;

class Config
{
    public function __construct(
        private IntegrationsHelper $integrationsHelper,
    ) {
    }

    public function isPublished(): bool
    {
        try {
            return (bool) $this->getIntegrationEntity()->getIsPublished();
        } catch (IntegrationNotFoundException) {
            return false;
        }
    }

    /** @return array<string, string> */
    public function getDecryptedApiKeys(): array
    {
        try {
            return $this->getIntegrationEntity()->getApiKeys() ?? [];
        } catch (IntegrationNotFoundException) {
            return [];
        }
    }

    private function getIntegrationEntity(): Integration
    {
        return $this->integrationsHelper
            ->getIntegration(LeuchtfeuerTranslationsIntegration::NAME)
            ->getIntegrationConfiguration();
    }
}
