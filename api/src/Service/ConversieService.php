<?php
namespace App\Service;

use App\Entity\Request;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ConversieService
{
    private $commonGroundService;
    private $params;
    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params){
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
    }

    public function convert(Request $request){
        $request = $this->commonGroundService->getResource($request);

        $requestType = $this->commonGroundService->getResource($request['requestType']);
        $caseType = $requestType['casetype'];

    }

}
