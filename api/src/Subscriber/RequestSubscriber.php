<?php

namespace App\Subscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Component;
use App\Entity\RequestConversion;
use App\Service\ConversionService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

class RequestSubscriber implements EventSubscriberInterface
{
    private $params;
    private $em;
    private $serializer;
    private $nlxLogService;
    private $conversionService;
    private $commonGroundService;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $em, SerializerInterface $serializer, ConversionService $conversionService, CommonGroundService $commonGroundService)
    {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
        $this->em = $em;
        $this->serializer = $serializer;
        $this->conversionService = $conversionService;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['Convert', EventPriorities::PRE_SERIALIZE],
        ];
    }

    public function Convert(ViewEvent $event)
    {
        $method = $event->getRequest()->getMethod();
        $contentType = $event->getRequest()->headers->get('accept');
        $route = $event->getRequest()->attributes->get('_route');
        $resource = $event->getControllerResult();

        if (!$contentType) {
            $contentType = $event->getRequest()->headers->get('Accept');
        }

        // We should also check on entity = component
        if ($method != 'POST') {
            return;
        }

        if ($resource instanceof RequestConversion) {
            $resource->getRequest();
            $request = $this->commonGroundService->getResource($resource->getRequest());

            if ($request['status'] != 'complete') {
                $resource = $this->conversionService->convert($resource);
            }
        }
        $this->em->persist($resource);
        $this->em->flush();
    }
}
