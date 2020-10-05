<?php

namespace App\Service;

use App\Entity\RequestConversion;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use DateTime;
use Exception;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConversionService
{
    private $commonGroundService;
    private $params;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params)
    {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
    }

    public function convert(RequestConversion $request)
    {
        $requestData = $this->commonGroundService->getResource($request->getRequest());
        if (key_exists('status', $requestData)) {
            switch ($requestData['status']) {
                case 'submitted':
                    $request = $this->createCase($request, $requestData, $requestData['status']);
                    $this->changeStatus($requestData['status'], $request, $requestData);
                    break;
                case 'inProgress':
                case 'processed':
                case 'rejected':
                case 'retracted':
                    $this->changeStatus($requestData['status'], $request, $requestData);
                    break;
                default:
                    break;
            }
        }

        return $request;
    }

    public function getStatusType($caseType, string $status)
    {
        $local = $this->commonGroundService->getLocal();
        $this->commonGroundService->setLocal(null);

        try {
            $statusTypes = $this->commonGroundService->getResourceList(['component'=>'ztc', 'type'=>'statustypen'], ['zaaktype'=>$caseType])['results'];
            foreach ($statusTypes as $statusType) {
//                echo "{$statusType['omschrijving']} == $status = ";
//                var_dump($statusType['omschrijving'] == $status);
                if ($statusType['omschrijving'] == $status) {
                    return $statusType['url'];
                }
            }
        } catch (Exception $exception) {
            echo 'see error!';
        }
        $this->commonGroundService->setLocal($local);
    }

    public function changeStatus(string $status, RequestConversion $request, array $requestData)
    {
        $cases = [];
        foreach ($requestData['cases'] as $case) {
            $jwt = $this->getJwtToken();
            $this->commonGroundService->setHeader('Authorization', "Bearer $jwt");

            try {
                $case = $this->commonGroundService->getResource($case);
                $statusType = $this->getStatusType($case['zaaktype'], $status);
                $statusObject = [];

                $statusObject['zaak'] = $case['url'];
                $statusObject['statustype'] = $statusType;
                $statusObject['datumStatusGezet'] = date('Y-m-d\Th:i:s');

                $statusObject = $this->commonGroundService->createResource($statusObject, ['component'=>'zrc', 'type'=>'statussen'], false, true, false);
            } catch (Exception $exception) {
                $this->commonGroundService->setHeader('Authorization', $this->params->get('app_application_key'));
            }
            $this->commonGroundService->setHeader('Authorization', $this->params->get('app_application_key'));
        }
    }

    public function createCase(RequestConversion $request, array $requestData, $status)
    {
        $requestType = $this->commonGroundService->getResource($requestData['requestType']);
        if (key_exists('caseType', $requestType)) {
            $caseType = $requestType['caseType'];
        }

        $case = [];
        $case['zaaktype'] = $caseType;
        $case['bronorganisatie'] = $this->commonGroundService->getResource($requestData['organization'])['rsin'];
        $case['verantwoordelijkeOrganisatie'] = $this->commonGroundService->getResource($requestData['organization'])['rsin'];
        $case['omschrijving'] = $requestData['name'];
        $case['startdatum'] = date('Y-m-d');

        $caseObject = [];
        $caseObject['object'] = $request->getRequest();
        $caseObject['objectType'] = 'overige';
        $caseObject['objectTypeOverige'] = 'verzoek';
        $caseObject['relatieomschrijving'] = 'verzoek behorende bij de zaak';

        $jwt = $this->getJwtToken();
        $this->commonGroundService->setHeader('Authorization', "Bearer $jwt");

        try {
            $case = $this->commonGroundService->createResource($case, ['component'=>'zrc', 'type'=>'zaken'], false, true, false);
            $caseObject['zaak'] = $case['url'];
//            echo json_encode($caseObject);
//            echo $jwt;
//            $caseObject = $this->commonGroundService->createResource($case, ['component'=>'zrc', 'type'=>'zaakobjecten'], false, true, false);

            $request->setStatus('OK');
            $request->setMessage('Verzoek omgezet naar zaak');

            $this->commonGroundService->setHeader('Authorization', $this->params->get('app_application_key'));
        } catch (Exception $exception) {
            $request->setMessage($exception->getMessage());
            $request->setStatus('FAILED');

            $token = [];
            $token['name'] = 'Zaak';
            $token['description'] = 'Verzoek omzetten naar een zaak';
            $token['reference'] = $this->params->get('app_name');
            $token['code'] = $this->params->get('app_name');
            $token['status'] = $request->getStatus();
            $token['message'] = $request->getMessage();
//            $token['resource'] = $case['url'];

//            var_dump($token);

             $this->commonGroundService->setHeader('Authorization', $this->params->get('app_application_key'));

//            var_dump($this->commonGroundService->cleanUrl(['component'=>'trc','type'=>'tokens']));
            $token = $this->commonGroundService->createResource($token, ['component'=>'trc', 'type'=>'tokens'], false, true, false);
//            var_dump($token);
            $request->setResult($token['@id']);

            return $request;
        }

        try {
            array_push($requestData['cases'], $case['url']);

            unset($requestData['submitters']);
            unset($requestData['roles']);
            unset($requestData['labels']);
            $requestData = $this->commonGroundService->updateResource($requestData, ['component'=>'vrc', 'type'=>'requests', 'id'=>$requestData['id']], false, true, false);
            $this->changeStatus($status, $request, $requestData);

            $token = [];
            $token['name'] = 'Zaak';
            $token['description'] = 'Verzoek omzetten naar een zaak';
            $token['reference'] = $this->params->get('app_name');
            $token['status'] = $request->getStatus();
            $token['message'] = $request->getMessage();
            $token['resource'] = $requestData['@id'];
        } catch (HttpException $exception) {
            $request->setMessage($exception->getMessage());
            $request->setStatus('FAILED');
            $token = [];
            $token['code'] = $this->params->get('app_name');
            $token['status'] = $request->getStatus();
            $token['message'] = $request->getMessage();
        }

        $token = $this->commonGroundService->createResource($token, ['component'=>'trc', 'type'=>'tokens'], false, true, false);
        $request->setResult($token['@id']);

        return $request;
    }

    public function getJwtToken()
    {
        $component = $this->commonGroundService->getComponent('zrc');
        $now = new DateTime('now');
        $jwsBuilder = new JWSBuilder(new AlgorithmManager([new HS256()]));

        $jwk = new JWK([
            'kty' => 'oct',
            'k'   => base64_encode(addslashes($component['secret'])),
        ]);
        $clientId = $component['id'];
        $payload = json_encode([
            'iss'                => $clientId,
            'iat'                => $now->getTimestamp(),
            'client_id'          => $clientId,
            'user_id'            => $this->params->get('app_name'),
            'user_representation'=> $this->params->get('app_name'),
        ]);
        $jws = $jwsBuilder
            ->create()
            ->withPayload($payload)
            ->addSignature($jwk, ['alg'=>'HS256'])
            ->build();

        $serializer = new CompactSerializer();

        return $serializer->serialize($jws, 0);
    }
}
