<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Integration;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;

class Config
{
    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private EncryptionHelper $encryptionHelper,
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
            $keys = $this->getIntegrationEntity()->getApiKeys() ?? [];
        } catch (IntegrationNotFoundException) {
            return [];
        }

        $decrypted = [];
        foreach ($keys as $name => $value) {
            try {
                $decrypted[$name] = $this->encryptionHelper->decrypt((string) $value);
            } catch (\Exception) {
                $decrypted[$name] = (string) $value;
            }
        }

        return $decrypted;
    }

    private function getIntegrationEntity(): Integration
    {
        return $this->integrationsHelper
            ->getIntegration(LeuchtfeuerTranslationsIntegration::NAME)
            ->getIntegrationConfiguration();
    }
}
