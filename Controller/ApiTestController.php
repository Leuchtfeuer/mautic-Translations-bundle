<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Controller;

use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiTestController extends AbstractController
{
    /**
     * POST /s/plugin/ai-translate/test-api (registered via config.php under "main").
     */
    public function testApiAction(DeeplClientService $deepl): JsonResponse
    {
        $result = $deepl->translate('Hello', 'DE');

        $isSuccess = (true === $result['success']);
        $message   = $isSuccess ? 'Success' : ($result['error'] ?? 'Unknown error');

        return new JsonResponse(['success' => $isSuccess, 'message' => $message]);
    }
}
