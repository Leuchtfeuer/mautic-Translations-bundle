<?php
namespace MauticPlugin\AiTranslateBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['inject', 0],
        ];
    }

    public function inject(CustomButtonEvent $event): void
    {
        $loc = (string) $event->getLocation();
        if ($loc !== 'page_actions') {
            // Only target the Options dropdown on the email detail page
            return;
        }

        $this->logger->info('[AiTranslate] injecting dropdown item', ['location' => $loc]);

        // Base href (JS will replace 0 with the real email ID)
        $href = $this->router->generate('plugin_ai_translate_action_translate', ['objectId' => 0]);

        $dropdownItem = [
            'attr'      => [
                'id'         => 'ai-translate-dropdown',
                // IMPORTANT: do NOT use "btn ..." classes here; dropdown uses link styles
                'class'      => ' -tertiary -nospin',
                'href'       => $href,
                'aria-label' => 'AI Translate',
                'onclick'    => <<<JS
(function(e){
  e.preventDefault();
  var m = (location.pathname || '').match(/\\/s\\/emails\\/view\\/(\\d+)/);
  var id = m ? m[1] : null;
  if(!id){ alert('Could not determine Email ID.'); return false; }

  var targetLang = prompt('Target language code (e.g. DE, FR, ES):','DE');
  if(!targetLang || targetLang.trim()===''){ return false; }

  try{ if(window.Mautic && Mautic.showLoadingBar){ Mautic.showLoadingBar(); } }catch(_){}

  var form = new URLSearchParams();
  form.append('targetLang', targetLang.trim().toUpperCase());

  fetch('/s/plugin/ai-translate/email/' + id + '/translate', { method: 'POST', body: form })
    .then(function(r){ return r.json(); })
    .then(function(d){ alert(d && d.message ? d.message : 'Done.'); })
    .catch(function(err){ console.error('AiTranslate error:', err); alert('Unexpected error, check console.'); })
    .finally(function(){ try{ if(window.Mautic && Mautic.stopLoadingBar){ Mautic.stopLoadingBar(); } }catch(_){ } });

  return false;
})(event);
JS
            ],
            'btnText'   => 'Clone & Translate',
            'iconClass' => 'ri-global-line',
            // FORCE it to dropdown:
            'primary'   => false,
            // Priority just affects ordering inside the dropdown
            'priority'  => 0.5,
        ];

        // Only on /s/emails/view/{id}
        $routeFilter = ['mautic_email_action', ['objectAction' => 'view']];

        // IMPORTANT: pass the explicit location name (not $loc) to avoid helper inference
        $event->addButton($dropdownItem, 'page_actions', $routeFilter);

        $this->logger->info('[AiTranslate] dropdown item added', ['location' => $loc]);
    }
}
