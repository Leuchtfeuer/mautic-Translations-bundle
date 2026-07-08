<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;

class TranslateActionFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    /** @var MockObject&DeeplClientService */
    private MockObject $deeplMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deeplMock = $this->createMock(DeeplClientService::class);
        static::getContainer()->set(DeeplClientService::class, $this->deeplMock);

        $this->createIntegration(published: true);
    }

    public function testHappyPathClonesEmailAndTranslatesSubject(): void
    {
        $email = $this->createEmail('Welcome Email', 'Hello World');
        $emailId = $email->getId();

        // Probe + subject translation both go through translate()
        $this->deeplMock->method('translate')
            ->willReturn([
                'success'     => true,
                'translation' => 'Hallo Welt',
                'host'        => 'https://api-free.deepl.com/v2/translate',
                'status'      => 200,
            ]);

        $this->client->request('POST', '/s/plugin/ai-translate/email/'.$emailId.'/translate', ['targetLang' => 'DE']);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $response->getContent(), true);
        Assert::assertTrue($data['success']);
        Assert::assertSame($emailId, $data['source']['emailId']);
        Assert::assertArrayHasKey('emailId', $data['clone']);

        // Verify the cloned email was persisted
        $this->em->clear();
        $clones = $this->em->getRepository(Email::class)->findBy(['name' => 'Welcome Email [DE]']);
        Assert::assertCount(1, $clones);
        Assert::assertFalse($clones[0]->isPublished());
        Assert::assertSame('de', $clones[0]->getLanguage());
        Assert::assertSame('Hallo Welt', $clones[0]->getSubject());
    }

    public function testReturns403WhenIntegrationIsDisabled(): void
    {
        // IntegrationsHelper caches the integration, so mock Config directly instead of updating DB
        $configMock = $this->createMock(Config::class);
        $configMock->method('isPublished')->willReturn(false);
        static::getContainer()->set(Config::class, $configMock);

        $email = $this->createEmail('Test Email', 'Hello');

        $this->client->request('POST', '/s/plugin/ai-translate/email/'.$email->getId().'/translate', ['targetLang' => 'DE']);

        $response = $this->client->getResponse();
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $data = json_decode((string) $response->getContent(), true);
        Assert::assertFalse($data['success']);
    }

    public function testReturns400WhenDeeplProbeFails(): void
    {
        $email = $this->createEmail('Test Email', 'Hello');

        $this->deeplMock->method('translate')
            ->willReturn([
                'success' => false,
                'error'   => 'API key invalid',
                'host'    => null,
                'status'  => Response::HTTP_FORBIDDEN,
            ]);

        $this->client->request('POST', '/s/plugin/ai-translate/email/'.$email->getId().'/translate', ['targetLang' => 'DE']);

        $response = $this->client->getResponse();
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode((string) $response->getContent(), true);
        Assert::assertFalse($data['success']);
    }

    public function testReturns404ForNonExistentEmail(): void
    {
        $this->deeplMock->method('translate')
            ->willReturn(['success' => true, 'translation' => 'test', 'host' => null, 'status' => 200]);

        $this->client->request('POST', '/s/plugin/ai-translate/email/99999/translate', ['targetLang' => 'DE']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testReturns400WhenTargetLangIsMissing(): void
    {
        $email = $this->createEmail('Test Email', 'Hello');

        $this->client->request('POST', '/s/plugin/ai-translate/email/'.$email->getId().'/translate', []);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    private function createEmail(string $name, string $subject = ''): Email
    {
        $email = new Email();
        $email->setName($name);
        $email->setSubject($subject);
        $email->setEmailType('template');
        $email->setCustomHtml('<p>Test</p>');
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    private function createIntegration(bool $published): void
    {
        $plugin = new Plugin();
        $plugin->setName('Translations by Leuchtfeuer');
        $plugin->setBundle('LeuchtfeuerTranslationsBundle');
        $plugin->setVersion('1.0.0');
        $this->em->persist($plugin);

        $integration = new Integration();
        $integration->setName(LeuchtfeuerTranslationsIntegration::NAME);
        $integration->setIsPublished($published);
        $integration->setPlugin($plugin);
        $integration->setApiKeys([]);
        $this->em->persist($integration);
        $this->em->flush();
    }
}
