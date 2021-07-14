<?php

namespace App\Service;

use App\Entity\RequestConversion;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConversionService
{
    private CommonGroundService $commonGroundService;
    private ParameterBagInterface $parameterBag;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $parameterBag)
    {
        $this->commonGroundService = $commonGroundService;
        $this->parameterBag = $parameterBag;
    }

    public function getStatusType($caseType, string $status)
    {
        try {
            $statusTypes = $this->commonGroundService->getResourceList(['component'=>'ztc', 'type'=>'statustypen'], ['zaaktype'=>$caseType])['results'];
            foreach ($statusTypes as $statusType) {
                if ($statusType['volgnummer'] == 1) {
                    return $statusType['url'];
                }
            }
        } catch (Exception $exception) {
            echo 'see error!';
        }

        return null;
    }

    public function changeStatus(string $status, RequestConversion $request, array $requestData)
    {
        $cases = [];
        foreach ($requestData['cases'] as $case) {
            $case = $this->commonGroundService->getResource($case);
            $statusType = $this->getStatusType($case['zaaktype'], $status);
            $statusObject = [];

            if ($statusType) {
                $statusObject['zaak'] = $case['url'];
                $statusObject['statustype'] = $statusType;
                $statusObject['datumStatusGezet'] = date('Y-m-d\Th:i:s');
                $statusObject = $this->commonGroundService->createResource($statusObject, ['component'=>'zrc', 'type'=>'statussen'], false, true, false);
            }
        }
    }

    public function createZaakObject(array $case, string $object, string $type, string $relation, ?string $description = null): array
    {
        $object = [
            'zaak'                  => $case['url'],
            'object'                => $object,
            'objectType'            => $type,
            'relatieOmschrijving'   => $relation,
        ];
        $description ? $object['objectTypeOverige'] = $description : null;

        return $this->commonGroundService->createResource($object, ['component' => 'zrc', 'type' => 'zaakobjecten']);
    }

    public function createCaseProperty(array $case, string $key, string $value): ?array
    {
        $properties = $this->commonGroundService->getResourceList(['component' => 'ztc', 'type' => 'eigenschappen'], ['zaaktype' => $case['zaaktype'], 'status' => 'alles'])['results'];
        foreach ($properties as $property) {
            if ($property['definitie'] == $key && $value) {
                $caseProperty = [
                    'zaak'          => $case['url'],
                    'eigenschap'    => $property['url'],
                    'waarde'        => substr($value, 0, 255),
                ];

                return $this->commonGroundService->createResource($caseProperty, ['component'=>'zrc', 'type'=>"zaken/{$case['id']}/zaakeigenschappen"]);
            }
        }

        return null;
    }

    public function convertProperties(array $properties, array $case): array
    {
        $results = [];
        foreach ($properties as $key=>$value) {
            if (filter_var($value, FILTER_VALIDATE_URL)) {
                $results[] = $this->createZaakObject($case, $value, 'overige', $key, 'verzoekobject');
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $results[] = $this->createZaakObject($case, $item, 'overige', $key, 'verzoekobject');
                }
            } else {
                $results[] = $this->createCaseProperty($case, $key, $value);
            }
        }

        return $results;
    }

    public function createSubmitter(array $case, string $submitter, string $submitterType): ?array
    {
        $roleTypes = $this->commonGroundService->getResourceList(['component' => 'ztc', 'type' => 'roltypen'], ['zaaktype' => $case['zaaktype'], 'status' => 'alles'])['results'];

        foreach ($roleTypes as $roleType) {
            if ($roleType['omschrijving'] == 'indiener') {
                $submitterObject = [
                    'zaak'              => $case['url'],
                    'betrokkene'        => $submitter,
                    'betrokkeneType'    => $submitterType,
                    'roltoelichting'    => 'De indiener van ze zaak vanuit een verzoek.',
                    'roltype'           => $roleType['url'],
                ];

                return $this->commonGroundService->createResource($submitterObject, ['component'=>'zrc', 'type'=>'rollen']);
            }
        }

        return null;
    }

    public function createCaseObjects(array $request, array $case): array
    {
        $caseObjects = $this->convertProperties($request['properties'], $case);
        $caseObjects[] = $this->createZaakObject($case, $request['@id'], 'overige', 'verzoek', 'bronverzoek');

        foreach ($request['submitters'] as $submitter) {
            if ($submitter['brp']) {
                $caseObjects[] = $this->createSubmitter($case, $submitter['brp'], 'natuurlijk_persoon', );
            } elseif ($submitter['person'] || $submitter['bsn'] || $submitter['assent']) {
                $caseObjects[] = $this->createSubmitter($case, $this->commonGroundService->cleanUrl(['component' => 'vrc', 'type' => 'submitters', 'id' => $submitter['id']]), 'natuurlijk_persoon');
            } else {
                $caseObjects[] = $this->createSubmitter($case, $this->commonGroundService->cleanUrl(['component' => 'vrc', 'type' => 'submitters', 'id' => $submitter['id']]), 'niet_natuurlijk_persoon');
            }
        }

        return $caseObjects;
    }

    public function createCase(RequestConversion $request, array $requestData, $status)
    {
        $explode = explode('/', $requestData['requestType']);
        $requestType = $this->commonGroundService->getResource(['component' => 'vtc', 'type' => 'request_types', 'id' => end($explode)]);
        if (key_exists('caseType', $requestType)) {
            $caseType = $requestType['caseType'];
        }

        $case = [];
        $case['zaaktype'] = $caseType;
        $array = explode('/', $requestData['organization']);
        $organization = $this->commonGroundService->getResource(['component' => 'wrc', 'type' => 'organizations', 'id' =>end($array)]);
        $case['bronorganisatie'] = $organization['rsin'];
        $case['verantwoordelijkeOrganisatie'] = $organization['rsin'];
        $case['omschrijving'] = $requestData['name'];
        $case['startdatum'] = date('Y-m-d');

        try {
            $case = $this->commonGroundService->createResource($case, ['component'=>'zrc', 'type'=>'zaken'], false, true, false);
            $properties = $this->createCaseObjects($requestData, $case);

            $request->setStatus('OK');
            $request->setMessage('Verzoek omgezet naar zaak');
        } catch (Exception $exception) {
            $request->setMessage($exception->getMessage());
            $request->setStatus('FAILED');

            return $request;
        }

        try {
            array_push($requestData['cases'], $case['url']);

            unset($requestData['submitters']);
            unset($requestData['order']);
            unset($requestData['roles']);
            unset($requestData['labels']);
            unset($requestData['parent']);
            unset($requestData['children']);
            $requestData = $this->commonGroundService->updateResource($requestData, ['component'=>'vrc', 'type'=>'requests', 'id'=>$requestData['id']], false, true, false);
            $this->changeStatus($status, $request, $requestData);
        } catch (HttpException $exception) {
            $request->setMessage($exception->getMessage());
            $request->setStatus('FAILED');
        }

        return $request;
    }

    public function convert(RequestConversion $request)
    {
        $this->commonGroundService->setLocal(null);
        $requestData = $this->commonGroundService->getResource($request->getResource());
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
//                    $this->changeStatus($requestData['status'], $request, $requestData);
                    break;
                default:
                    break;
            }
        }

        return $request;
    }
}
