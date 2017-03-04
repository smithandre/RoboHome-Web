<?php

namespace App\Http\Controllers\API;

use App\Device;
use App\Http\Controllers\Common\Controller;
use App\Http\Globals\DeviceActions;
use App\Http\MQTT\MessagePublisher;
use App\User;
use Illuminate\Http\Request;

class DevicesController extends Controller
{
    private $deviceModel;
    private $userModel;
    private $messagePublisher;

    public function __construct(Device $deviceModel, User $userModel, MessagePublisher $messagePublisher)
    {
        $this->middleware('apiAuthenticator');

        $this->deviceModel = $deviceModel;
        $this->userModel = $userModel;
        $this->messagePublisher = $messagePublisher;
    }

    public function index(Request $request)
    {
        $userId = $request->get('currentUserId');

        $devicesForCurrentUser = $this->currentUser($userId)->devices;

        $response = [
            'header' => $this->createHeader($request, 'DiscoverAppliancesResponse', 'Alexa.ConnectedHome.Discovery'),
            'payload' => [
                'discoveredAppliances' => $this->buildAppliancesJson($devicesForCurrentUser)
            ]
        ];

        return response()->json($response);
    }

    public function turnOn(Request $request)
    {
        $response = $this->handleControlRequest($request, DeviceActions::TURN_ON, 'TurnOnConfirmation');

        return $response;
    }

    public function turnOff(Request $request)
    {
        $response = $this->handleControlRequest($request, DeviceActions::TURN_OFF, 'TurnOffConfirmation');

        return $response;
    }

    private function handleControlRequest(Request $request, $action, $responseName)
    {
        $userId = $request->get('currentUserId');
        $deviceId = $request->input('id');

        $doesUserOwnDevice = $this->currentUser($userId)->doesUserOwnDevice($deviceId);

        if (!$doesUserOwnDevice) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $urlValidAction = strtolower($action);

        $this->messagePublisher->publish($userId, $deviceId, $urlValidAction);

        $response = [
            'header' => $this->createHeader($request, $responseName, 'Alexa.ConnectedHome.Control'),
            'payload' => (object)[]
        ];

        return response()->json($response);
    }

    private function buildAppliancesJson($devicesForCurrentUser)
    {
        $actions = [DeviceActions::TURN_ON, DeviceActions::TURN_OFF];

        $appliances = [];

        for ($i = 0; $i < count($devicesForCurrentUser); $i++) {
            $appliance = [
                'actions' => $actions,
                'additionalApplianceDetails' => (object)[],
                'applianceId' => $devicesForCurrentUser[$i]->id,
                'friendlyName' => $devicesForCurrentUser[$i]->name,
                'friendlyDescription' => $devicesForCurrentUser[$i]->description,
                'isReachable' => true,
                'manufacturerName' => 'N/A',
                'modelName' => 'N/A',
                'version' => 'N/A'
            ];

            array_push($appliances, $appliance);
        }

        return $appliances;
    }

    private function createHeader(Request $request, $responseName, $namespace)
    {
        $messageId = $request->header('Message-Id');

        $header = [
            'messageId' => $messageId,
            'name' => $responseName,
            'namespace' => $namespace,
            'payloadVersion' => '2'
        ];

        return $header;
    }

    private function currentUser($userId)
    {
        $currentUser = $this->userModel->where('user_id', $userId)->first();

        return $currentUser;
    }
}