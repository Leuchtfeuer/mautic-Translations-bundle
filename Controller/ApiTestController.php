<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Controller;

use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiTestController extends AbstractController
{
    /**
     * POST /s/plugin/ai-translate/test-api (registered via config.php under "main").
     */
    public function testApiAction(DeeplClientService $deepl, Config $config): JsonResponse
    {
        if (!$config->isPublished()) {
            return new JsonResponse(['success' => false, 'message' => 'Integration is disabled.'], Response::HTTP_FORBIDDEN);
        }

        $result = $deepl->translate('Hello', 'DE');

        $isSuccess = (true === $result['success']);
        $message   = $isSuccess ? 'Success' : ($result['error'] ?? 'Unknown error');

        return new JsonResponse(['success' => $isSuccess, 'message' => $message]);
    }
}
