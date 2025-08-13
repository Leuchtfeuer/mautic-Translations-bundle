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

        // DeepL wants uppercase; Mautic Email.language wants lowercase ISO 639-1
        $targetLangApi = strtoupper((string) $request->get('targetLang', '')); // e.g. "DE"
        if ($targetLangApi === '') {
            return new JsonResponse(['success' => false, 'message' => 'Target language not provided.'], Response::HTTP_BAD_REQUEST);
        }
        $targetLangIso = strtolower($targetLangApi); // e.g. "de"

        $sourceLangGuess = strtolower($sourceEmail->getLanguage() ?: ''); // normalize for consistency
        $emailName       = $sourceEmail->getName() ?: '';
        $isCodeMode      = $sourceEmail->getTemplate() === 'mautic_code_mode';

        // 1) Quick DeepL probe (use UPPER for API)
        $probe = $deepl->translate('Hello from Mautic', $targetLangApi);

        // 2) Read MJML from GrapesJS builder storage (bundle_grapesjsbuilder.custom_mjml)
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

        // 3) Clone the entity (Mautic pattern) and FIX language casing
        try {
            $emailType = $sourceEmail->getEmailType();

            /** @var Email $clone */
            $clone = clone $sourceEmail;

            // Restore fields / set our adjustments
            $clone->setIsPublished(false);
            $clone->setEmailType($emailType);
            $clone->setVariantParent(null);

            // Name + target language suffix
            $clone->setName(rtrim(($emailName ?: 'Email').' ['.$targetLangApi.']'));

            // IMPORTANT: Mautic expects lowercase language code
            $clone->setLanguage($targetLangIso ?: $sourceLangGuess);

            // Ensure HTML is not null (prevents PlainTextHelper error on /view)
            $sourceHtml = $sourceEmail->getCustomHtml();
            if ($sourceHtml === null) {
                $sourceHtml = '<!doctype html><html><body></body></html>';
            }
            $clone->setCustomHtml($sourceHtml);

            // Persist clone to get its ID
            $model->saveEntity($clone);
        } catch (\Throwable $e) {
            $logger->error('[AiTranslate] Clone (entity __clone) failed', ['ex' => $e->getMessage()]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to clone email: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4) Write MJML for the cloned email ID (copy source MJML if present)
        $cloneId   = (int) $clone->getId();
        $mjmlWrite = false;

        if ($mjml !== '') {
            try {
                // UPDATE first
                $affected = $conn->update(
                    'bundle_grapesjsbuilder',
                    ['custom_mjml' => $mjml],
                    ['email_id' => $cloneId]
                );

                if ($affected === 0) {
                    // If no row existed yet, INSERT
                    $conn->insert('bundle_grapesjsbuilder', [
                        'email_id'    => $cloneId,
                        'custom_mjml' => $mjml,
                    ]);
                }

                $mjmlWrite = true;
            } catch (\Throwable $e) {
                $logger->error('[AiTranslate] Failed to write MJML for clone', [
                    'cloneId' => $cloneId,
                    'ex'      => $e->getMessage(),
                ]);
            }
        }

        // 5) Prepare response
        $payload = [
            'success'   => true,
            'message'   => 'Probe OK. Cloned email. (No content translation yet.)',
            'source'    => [
                'emailId'   => $sourceEmail->getId(),
                'name'      => $emailName,
                'language'  => $sourceLangGuess,      // e.g. "en"
                'template'  => $sourceEmail->getTemplate(),
            ],
            'clone'     => [
                'emailId'   => $cloneId,
                'name'      => $clone->getName(),
                'subject'   => $clone->getSubject(),
                'subjectTranslated' => false,
                'template'  => $clone->getTemplate(),
                'language'  => $clone->getLanguage(), // should be lowercase (e.g. "de")
                'mjmlWrite' => $mjmlWrite,
                'urls'      => [
                    'edit'    => $request->getSchemeAndHttpHost().'/s/emails/edit/'.$cloneId,
                    'view'    => $request->getSchemeAndHttpHost().'/s/emails/view/'.$cloneId,
                    'builder' => $request->getSchemeAndHttpHost().'/s/emails/builder/'.$cloneId,
                ],
            ],
            'deeplProbe' => [
                'success'     => (bool) ($probe['success'] ?? false),
                'translation' => $probe['translation'] ?? null,
                'error'       => $probe['error'] ?? null,
                'apiKey'      => $probe['apiKey'] ?? null,
                'host'        => $probe['host'] ?? null,
                'status'      => $probe['status'] ?? null,
                'body'        => $probe['body'] ?? null,
            ],
            'note' => 'Cloned entity + copied MJML and HTML. Next step: translate content inside MJML.',
        ];

        $logger->info('[AiTranslate] clone done (entity __clone)', [
            'sourceId'   => $sourceEmail->getId(),
            'cloneId'    => $cloneId,
            'mjmlWrite'  => $mjmlWrite,
            'cloneLang'  => $clone->getLanguage(),
        ]);

        return new JsonResponse($payload);
    }
}
