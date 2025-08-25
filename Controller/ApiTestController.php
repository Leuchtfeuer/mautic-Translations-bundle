<?php

namespace MauticPlugin\AiTranslateBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use MauticPlugin\AiTranslateBundle\Service\DeeplClientService;

class ApiTestController extends AbstractController
{
    /**
     * POST /s/plugin/ai-translate/test-api (registered via config.php under "main")
     */
    public function testApiAction(DeeplClientService $deepl): JsonResponse
    {
        $result = $deepl->translate('Hello', 'DE');

        if (!empty($result['success'])) {
            $message = sprintf('Success! "Hello" → "%s"', (string) ($result['translation'] ?? ''));
        } else {
            $message = sprintf('Error: %s', (string) ($result['error'] ?? 'Unknown error'));
        }

        return new JsonResponse(['message' => $message]);
    }
}
