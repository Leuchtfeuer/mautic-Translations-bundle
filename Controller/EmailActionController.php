<?php
// plugins/AiTranslateBundle/Controller/EmailActionController.php

namespace MauticPlugin\AiTranslateBundle\Controller;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\AiTranslateBundle\Service\DeeplClientService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EmailActionController extends FormController
{
    public function translateAction(
        Request $request,
        int $objectId,
        DeeplClientService $deepl,
        LoggerInterface $logger,
        CorePermissions $security,
        Connection $conn
    ): Response {
        $logger->info('[AiTranslate] translateAction start', [
            'objectId'   => $objectId,
            'targetLang' => $request->get('targetLang'),
        ]);

        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model = $this->getModel('email');

        /** @var Email|null $sourceEmail */
        $sourceEmail = $model->getEntity($objectId);

        if (
            null === $sourceEmail ||
            !$security->hasEntityAccess(
                'email:emails:view:own',
                'email:emails:view:other',
                $sourceEmail->getCreatedBy()
            )
        ) {
            $logger->warning('[AiTranslate] email not found or access denied', ['objectId' => $objectId]);
            return new JsonResponse(['success' => false, 'message' => 'Email not found or access denied.'], Response::HTTP_NOT_FOUND);
        }

        $targetLang = strtoupper((string) $request->get('targetLang', ''));
        if ($targetLang === '') {
            return new JsonResponse(['success' => false, 'message' => 'Target language not provided.'], Response::HTTP_BAD_REQUEST);
        }

        $sourceLangGuess = $sourceEmail->getLanguage() ?: '';
        $emailName       = $sourceEmail->getName() ?: '';
        $isCodeMode      = $sourceEmail->getTemplate() === 'mautic_code_mode';

        // 1) Probe DeepL quickly
        $probe = $deepl->translate('Hello from Mautic', $targetLang);

        // 2) Read MJML from GrapesJS builder storage:
        //    table: bundle_grapesjsbuilder, column: custom_mjml, key: email_id
        $mjml     = '';
        $tableHit = null;

        try {
            $sql  = 'SELECT custom_mjml FROM bundle_grapesjsbuilder WHERE email_id = :id LIMIT 1';
            $row  = $conn->fetchAssociative($sql, ['id' => $sourceEmail->getId()]);
            $mjml = isset($row['custom_mjml']) ? (string) $row['custom_mjml'] : '';
            $tableHit = 'bundle_grapesjsbuilder.custom_mjml';
        } catch (\Throwable $e) {
            $logger->error('[AiTranslate] Failed to fetch MJML from bundle_grapesjsbuilder', [
                'emailId' => $sourceEmail->getId(),
                'ex'      => $e->getMessage(),
            ]);
        }

        $snippet = mb_substr($mjml, 0, 200);
        $logger->info('[AiTranslate] MJML fetch result', [
            'emailId'     => $sourceEmail->getId(),
            'table'       => $tableHit,
            'found'       => $mjml !== '',
            'length'      => mb_strlen($mjml),
            'snippet_len' => mb_strlen($snippet),
            'codeMode'    => $isCodeMode,
        ]);

        $payload = [
            'success'         => (bool) ($probe['success'] ?? false),
            'message'         => $probe['success']
                ? 'Probe OK. MJML fetched. Next step: clone (no translation yet).'
                : ('Probe failed: '.($probe['error'] ?? 'Unknown error')),
            'emailId'         => $sourceEmail->getId(),
            'emailName'       => $emailName,
            'sourceLangGuess' => $sourceLangGuess,
            'targetLang'      => $targetLang,
            'isCodeMode'      => $isCodeMode,
            'mjml'            => [
                'table'   => $tableHit,
                'found'   => $mjml !== '',
                'length'  => mb_strlen($mjml),
                'snippet' => $snippet,
            ],
            'deeplProbe'      => [
                'success'     => (bool) ($probe['success'] ?? false),
                'translation' => $probe['translation'] ?? null,
                'error'       => $probe['error'] ?? null,
                'apiKey'      => $probe['apiKey'] ?? null,   // << RAW KEY for verification
                'host'        => $probe['host'] ?? null,
                'status'      => $probe['status'] ?? null,
                'body'        => $probe['body'] ?? null,
            ],
            'note'            => 'Dry run: only fetched MJML. No cloning or translation yet.',
        ];

        $logger->info('[AiTranslate] translateAction payload (probe + mjml)', [
            'success'    => $payload['success'],
            'mjml_found' => $payload['mjml']['found'],
            'mjml_table' => $payload['mjml']['table'],
            'codeMode'   => $isCodeMode,
        ]);

        return new JsonResponse($payload);
    }
}
