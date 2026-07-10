<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Controller;

use Mautic\CoreBundle\Controller\AbstractFormController;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder;
use MauticPlugin\GrapesJsBuilderBundle\Model\GrapesJsBuilderModel;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlCompileService;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlTranslateService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailActionController extends AbstractFormController
{
    public function translateAction(
        Request $request,
        int $objectId,
        DeeplClientService $deepl,
        MjmlTranslateService $mjmlService,
        MjmlCompileService $mjmlCompiler,
        LoggerInterface $logger,
        CorePermissions $security,
        TranslatorInterface $translator,
        Config $config,
    ): Response {
        $logger->info('[LeuchtfeuerTranslations] translateAction start', [
            'objectId'   => $objectId,
            'targetLang' => $request->get('targetLang'),
        ]);

        if (!$config->isPublished()) {
            $logger->info('[LeuchtfeuerTranslations] translateAction blocked: integration disabled');

            return $this->errorJson($translator, 'plugin.leuchtfeuertranslations.error.integration_disabled', Response::HTTP_FORBIDDEN);
        }

        /** @var EmailModel $model */
        $model       = $this->getModel(EmailModel::class);
        $sourceEmail = $model->getEntity($objectId);

        if (null === $sourceEmail || !$security->hasEntityAccess('email:emails:view:own', 'email:emails:view:other', $sourceEmail->getCreatedBy())) {
            $logger->warning('[LeuchtfeuerTranslations] email not found or access denied', ['objectId' => $objectId]);

            return $this->errorJson($translator, 'plugin.leuchtfeuertranslations.error.email_not_found_or_access_denied', Response::HTTP_NOT_FOUND);
        }

        $targetLangRaw = $this->resolveTargetLang($request);
        if ('' === $targetLangRaw) {
            return $this->errorJson($translator, 'plugin.leuchtfeuertranslations.error.target_language_missing', Response::HTTP_BAD_REQUEST);
        }

        $targetLangApi = strtoupper($targetLangRaw);
        $targetLangIso = strtolower($targetLangApi);

        $probe = $deepl->translate('Hello from Mautic', $targetLangApi);
        if (true !== $probe['success']) {
            $logger->error('[LeuchtfeuerTranslations] DeepL probe failed', [
                'error'  => (isset($probe['error']) ? (string) $probe['error'] : ''),
                'host'   => $probe['host'],
                'status' => $probe['status'],
            ]);

            return $this->errorJson($translator, 'plugin.leuchtfeuertranslations.error.deepl_probe_failed', Response::HTTP_BAD_REQUEST);
        }

        /** @var GrapesJsBuilderModel $grapesModel */
        $grapesModel = $this->getModel(GrapesJsBuilderModel::class);
        $mjml        = $this->fetchMjml((int) $sourceEmail->getId(), $grapesModel, $logger);

        try {
            $clone = $this->persistClone($sourceEmail, $targetLangApi, $targetLangIso, $model);
        } catch (\Throwable $e) {
            $logger->error('[LeuchtfeuerTranslations] Clone (entity __clone) failed', ['ex' => $e->getMessage()]);

            return $this->errorJson($translator, 'plugin.leuchtfeuertranslations.error.clone_failed', Response::HTTP_INTERNAL_SERVER_ERROR, ['%error%' => $e->getMessage()]);
        }

        $cloneId = $clone->getId();
        if (null === $cloneId) {
            $logger->error('[LeuchtfeuerTranslations] Clone persisted but ID is still null');

            return $this->errorJson($translator, 'plugin.leuchtfeuertranslations.error.clone_persist_failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $wroteMjml = $this->saveMjmlToClone($cloneId, $clone, $mjml, $grapesModel, $logger);

        try {
            $translation = $this->translateAndCompile($clone, $cloneId, $mjml, $targetLangApi, $mjmlService, $mjmlCompiler, $grapesModel, $model, $logger);
        } catch (\Throwable $e) {
            return $this->errorJson($translator, 'plugin.leuchtfeuertranslations.error.translation_failed', Response::HTTP_INTERNAL_SERVER_ERROR, ['%error%' => $e->getMessage()]);
        }

        $logger->info('[LeuchtfeuerTranslations] translateAction finished', [
            'cloneId' => $cloneId,
            'changed' => $translation,
        ]);

        return new JsonResponse($this->buildSuccessPayload($sourceEmail, $clone, $cloneId, $wroteMjml, $translation, $translator));
    }

    private function resolveTargetLang(Request $request): string
    {
        $raw = $request->isMethod('POST')
            ? $request->request->get('targetLang')
            : $request->query->get('targetLang');

        return is_string($raw) ? trim($raw) : '';
    }

    private function fetchMjml(int $emailId, GrapesJsBuilderModel $grapesModel, LoggerInterface $logger): string
    {
        try {
            $grapes = $grapesModel->getGrapesJsFromEmailId($emailId);

            return $grapes?->getCustomMjml() ?? '';
        } catch (\Throwable $e) {
            $logger->error('[LeuchtfeuerTranslations] Failed to fetch MJML via GrapesJsBuilderModel', [
                'emailId' => $emailId,
                'ex'      => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * @throws \Throwable
     */
    private function persistClone(Email $source, string $targetLangApi, string $targetLangIso, EmailModel $model): Email
    {
        $emailName  = is_string($source->getName()) ? $source->getName() : '';
        $sourceLang = is_string($source->getLanguage()) ? strtolower($source->getLanguage()) : '';

        $clone = clone $source;
        $clone->setIsPublished(false);
        $clone->setEmailType($source->getEmailType());
        $clone->setVariantParent();
        $clone->setContent([]);
        $clone->setName(('' !== $emailName ? $emailName : 'Email').' ['.$targetLangApi.']');
        $clone->setLanguage('' !== $targetLangIso ? $targetLangIso : $sourceLang);
        $clone->setCustomHtml($source->getCustomHtml() ?? '<!doctype html><html><body></body></html>');

        $model->saveEntity($clone);

        return $clone;
    }

    private function saveMjmlToClone(int $cloneId, Email $clone, string $mjml, GrapesJsBuilderModel $grapesModel, LoggerInterface $logger): bool
    {
        if ('' === $mjml) {
            return false;
        }

        try {
            $cloneGrapes = $grapesModel->getGrapesJsFromEmailId($cloneId);
            if (null === $cloneGrapes) {
                $cloneGrapes = new GrapesJsBuilder();
                $cloneGrapes->setEmail($clone);
            }
            if ($cloneGrapes->getCustomMjml() !== $mjml) {
                $cloneGrapes->setCustomMjml($mjml);
                $grapesModel->getRepository()->saveEntity($cloneGrapes);
            }

            return true;
        } catch (\Throwable $e) {
            $logger->error('[LeuchtfeuerTranslations] Failed initial MJML write for clone', [
                'cloneId' => $cloneId,
                'ex'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{translatedSubject: string|null, translatedMjml: string|null, samples: array<int, array{from:string,to:string}>, mj: array<string, mixed>}
     */
    private function translateAndCompile(
        Email $clone,
        int $cloneId,
        string $mjml,
        string $targetLangApi,
        MjmlTranslateService $mjmlService,
        MjmlCompileService $mjmlCompiler,
        GrapesJsBuilderModel $grapesModel,
        EmailModel $model,
        LoggerInterface $logger,
    ): array {
        $translatedSubject = null;
        $translatedMjml    = null;
        $samples           = [];
        $mj                = [];

        try {
            $origSubject = is_string($clone->getSubject()) ? $clone->getSubject() : '';
            if ('' !== $origSubject) {
                $translatedSubject = $mjmlService->translateRichText($origSubject, $targetLangApi, $samples);
                if ($translatedSubject !== $origSubject) {
                    $clone->setSubject($translatedSubject);
                }
            }

            if ('' !== $mjml) {
                $mj             = $mjmlService->translateMjml($mjml, $targetLangApi);
                $translatedMjml = $mj['mjml'];

                $this->saveTranslatedMjml($cloneId, $clone, $translatedMjml, $grapesModel);

                $compiled     = $mjmlCompiler->compile($translatedMjml);
                $compiledHtml = isset($compiled['html']) && is_string($compiled['html']) ? $compiled['html'] : '';

                if ($compiled['success'] && '' !== $compiledHtml) {
                    $clone->setCustomHtml($compiledHtml);
                } else {
                    $logger->warning('[LeuchtfeuerTranslations] MJML compile failed; keeping existing custom_html', [
                        'cloneId' => $cloneId,
                        'error'   => $compiled['error'] ?? 'unknown',
                    ]);
                }
            }

            $model->saveEntity($clone);
        } catch (\Throwable $e) {
            $logger->error('[LeuchtfeuerTranslations] Translation / compile step failed', ['cloneId' => $cloneId, 'ex' => $e->getMessage()]);
            throw $e;
        }

        return [
            'translatedSubject' => $translatedSubject,
            'translatedMjml'    => $translatedMjml,
            'samples'           => $samples,
            'mj'                => $mj,
        ];
    }

    private function saveTranslatedMjml(int $cloneId, Email $clone, string $translatedMjml, GrapesJsBuilderModel $grapesModel): void
    {
        $cloneGrapes = $grapesModel->getGrapesJsFromEmailId($cloneId);
        if (null === $cloneGrapes) {
            $cloneGrapes = new GrapesJsBuilder();
            $cloneGrapes->setEmail($clone);
        }
        if ($cloneGrapes->getCustomMjml() !== $translatedMjml) {
            $cloneGrapes->setCustomMjml($translatedMjml);
            $grapesModel->getRepository()->saveEntity($cloneGrapes);
        }
    }

    /**
     * @param array{translatedSubject: string|null, translatedMjml: string|null, samples: array<int, array{from:string,to:string}>, mj: array<string, mixed>} $translation
     *
     * @return array<string, mixed>
     */
    private function buildSuccessPayload(
        Email $sourceEmail,
        Email $clone,
        int $cloneId,
        bool $wroteMjml,
        array $translation,
        TranslatorInterface $translator,
    ): array {
        $emailName   = is_string($sourceEmail->getName()) ? $sourceEmail->getName() : '';
        $sourceLang  = is_string($sourceEmail->getLanguage()) ? strtolower($sourceEmail->getLanguage()) : '';
        $mj          = $translation['mj'];
        $lockedMode  = isset($mj['lockedMode']) && (bool) $mj['lockedMode'];
        $lockedPairs = isset($mj['lockedPairs']) ? (int) $mj['lockedPairs'] : 0;

        return [
            'success' => true,
            'message' => $translator->trans('plugin.leuchtfeuertranslations.done'),
            'source'  => [
                'emailId'  => $sourceEmail->getId(),
                'name'     => $emailName,
                'language' => $sourceLang,
                'template' => $sourceEmail->getTemplate(),
            ],
            'clone'   => [
                'emailId'   => $cloneId,
                'name'      => $clone->getName(),
                'subject'   => $clone->getSubject(),
                'template'  => $clone->getTemplate(),
                'language'  => $clone->getLanguage(),
                'mjmlWrite' => $wroteMjml,
                'urls'      => [
                    'edit'    => $this->generateUrl('mautic_email_action', ['objectAction' => 'edit',    'objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                    'view'    => $this->generateUrl('mautic_email_action', ['objectAction' => 'view',    'objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                    'builder' => $this->generateUrl('mautic_email_action', ['objectAction' => 'builder', 'objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                    'preview' => $this->generateUrl('mautic_email_preview', ['objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
            ],
            'translation' => [
                'subjectChanged' => null !== $translation['translatedSubject'],
                'mjmlChanged'    => null !== $translation['translatedMjml'],
                'samples'        => array_slice($translation['samples'], 0, 4),
                'lockedMode'     => $lockedMode,
                'lockedPairs'    => $lockedPairs,
            ],
            'note' => $translator->trans('plugin.leuchtfeuertranslations.note_compiled_from_translated_mjml'),
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function errorJson(TranslatorInterface $translator, string $key, int $status, array $params = []): JsonResponse
    {
        return new JsonResponse(
            ['success' => false, 'message' => $translator->trans($key, $params)],
            $status
        );
    }
}
