<?php
// plugins/AiTranslateBundle/Controller/EmailActionController.php

namespace MauticPlugin\AiTranslateBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\EmailBundle\Entity\Email;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EmailActionController extends FormController
{
    /**
     * The action called by our "Clone & Translate" button.
     *
     * @param Request $request
     * @param int     $objectId The ID of the source email
     *
     * @return JsonResponse
     */
    public function translateAction(Request $request, int $objectId): Response
    {
        // Use the Mautic-way to get the email model and entity
        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model = $this->getModel('email');
        /** @var Email $sourceEmail */
        $sourceEmail = $model->getEntity($objectId);

        // Basic permission and existence check
        if (null === $sourceEmail || !$this->get('mautic.security')->hasEntityAccess(
                'email:emails:view:own',
                'email:emails:view:other',
                $sourceEmail->getCreatedBy()
            )) {
            return new JsonResponse(['success' => false, 'message' => 'Email not found or access denied.'], Response::HTTP_NOT_FOUND);
        }

        // Get the target language from the AJAX POST data
        $targetLang = $request->get('targetLang');
        if (empty($targetLang)) {
            return new JsonResponse(['success' => false, 'message' => 'Target language not provided.'], Response::HTTP_BAD_REQUEST);
        }

        // --- Future Logic Placeholder ---
        // This is where you will call your DeeplClientService, parse MJML, etc.
        // For now, we just return a success message to confirm the wiring.

        $message = sprintf(
            'SUCCESS: Received request to translate Email ID %d ("%s") to language: %s. The real work happens next!',
            $sourceEmail->getId(),
            $sourceEmail->getName(),
            strtoupper($targetLang)
        );

        return new JsonResponse(['success' => true, 'message' => $message]);
    }
}