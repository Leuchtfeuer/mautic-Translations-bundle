<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\EventListener;

use Mautic\IntegrationsBundle\Event\KeysSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ApiKeySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [IntegrationEvents::INTEGRATION_API_KEYS_BEFORE_SAVE => 'onApiKeysBeforeSave'];
    }

    public function onApiKeysBeforeSave(KeysSaveEvent $event): void
    {
        if (LeuchtfeuerTranslationsIntegration::NAME !== $event->getIntegrationConfiguration()->getName()) {
            return;
        }

        $field = 'deepl_api_key';

        if ('' !== ($event->getNewKeys()[$field] ?? '')) {
            return; // new key was submitted — keep it
        }

        if ('' === ($event->getOldKeys()[$field] ?? '')) {
            return; // nothing stored yet — nothing to restore
        }

        $integration = $event->getIntegrationConfiguration();
        $integration->setApiKeys(array_merge(
            $integration->getApiKeys() ?? [],
            [$field => $event->getOldKeys()[$field]]
        ));
    }
}
