<?php
namespace App\Service;

use App\Entity\RequestConversion;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\KeyManagement\KeyConverter\RSAKey;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Easy\Build;
use Jose\Easy\JWT;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConversionService
{
    private $commonGroundService;
    private $params;
    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params){
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
    }

    public function convert(RequestConversion $request){
        $requestData = $this->commonGroundService->getResource($request->getRequest());

        $requestType = $this->commonGroundService->getResource($requestData['requestType']);
        if(key_exists('caseType', $requestType))
        $caseType = $requestType['caseType'];

        $case = [];
        $case['zaaktype'] = $caseType;
        $case['bronorganisatie'] = $this->commonGroundService->getResource($requestData['organization'])['rsin'];
        $case['verantwoordelijkeOrganisatie'] = $this->commonGroundService->getResource($requestData['organization'])['rsin'];
        $case['omschrijving'] = $requestData['name'];
        $case['startdatum'] = date('Y-m-d');

        $jwt = $this->getJwtToken();
        $this->commonGroundService->setHeader('Authorization',"Bearer $jwt");

        try{
            $case = $this->commonGroundService->createResource($case,['component'=>'zrc','type'=>'zaken']);
            $request->setStatus('OK');
            $request->setMessage('Verzoek omgezet naar zaak');

            $this->commonGroundService->setHeader('Authorization',$this->params->get('app_application_key'));


        }catch(HttpException $exception){
            $request->setMessage($exception->getMessage());
            $request->setStatus('FAILED');

            $token = [];
            $token['name'] = "Zaak";
            $token['description'] = 'Verzoek omzetten naar een zaak';
            $token['reference'] = $this->params->get('app_name');
            $token['code'] = $this->params->get('app_name');
            $token['status'] = $request->getStatus();
            $token['message'] = $request->getMessage();
//            $token['resource'] = $case['url'];

//            var_dump($token);

            $this->commonGroundService->setHeader('Authorization',$this->params->get('app_application_key'));
//            var_dump($this->commonGroundService->cleanUrl(['component'=>'trc','type'=>'tokens']));
            $token = $this->commonGroundService->createResource($token, ['component'=>'trc','type'=>'tokens']);
//            var_dump($token);
            $request->setResult($token['@id']);

            return $request;
        }

        try{
            array_push($requestData['cases'], $case['url']);

            unset($requestData['submitters']);
            unset($requestData['roles']);
            unset($requestData['labels']);
            $this->commonGroundService->updateResource($requestData, ['component'=>'vrc','type'=>'requests','id'=>$requestData['id']]);

            $token = [];
            $token['name'] = 'Zaak';
            $token['description'] = 'Verzoek omzetten naar een zaak';
            $token['reference'] = $this->params->get('app_name');
            $token['status'] = $request->getStatus();
            $token['message'] = $request->getMessage();
            $token['resource'] = $requestData['@id'];
        }catch(HttpException $exception){
            $request->setMessage($exception->getMessage());
            $request->setStatus('FAILED');
            $token = [];
            $token['code'] = $this->params->get('app_name');
            $token['status'] = $request->getStatus();
            $token['message'] = $request->getMessage();
        }

        $token = $this->commonGroundService->createResource($token, ['component'=>'trc','type'=>'tokens']);
        $request->setResult($token['@id']);

        return $request;


    }
    public function getJwtToken(){
        $component = $this->commonGroundService->getComponent('zrc');
        $now = new DateTime('now');
        $jwsBuilder = new JWSBuilder(new AlgorithmManager([new HS256()]));

        $jwk = new JWK([
            'kty' => 'oct',
            'k'=>base64_encode(addslashes($component['secret'])),
        ]);
        $clientId = $component['id'];
        $payload = json_encode([
            'iss'=>$clientId,
            'iat'=>$now->getTimestamp(),
            'client_id'=>$clientId,
            'user_id'=>$this->params->get('app_name'),
            'user_representation'=>$this->params->get('app_name'),
        ]);
        $jws = $jwsBuilder
            ->create()
            ->withPayload($payload)
            ->addSignature($jwk,['alg'=>'HS256'])
            ->build();

        $serializer = new CompactSerializer();
        return $serializer->serialize($jws, 0);
    }

}
