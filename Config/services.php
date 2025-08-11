<?php
declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()->autowire()->autoconfigure()->public();

    // Autoload everything under the bundle (except default excludes)
    $services->load('MauticPlugin\\AiTranslateBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    // (Optional explicit) keep Deepl client explicit if you like
    $services->set(MauticPlugin\AiTranslateBundle\Service\DeeplClientService::class)
        ->args(['@mautic.helper.integration']);
};
