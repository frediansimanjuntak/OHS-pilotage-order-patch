<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Modules\Berth\Entities\Berth;
use Modules\Berth\Entities\Location;
use Modules\Order\Entities\PilotageOrder;
use Modules\Order\Entities\VesselPilotOrder;
use Modules\Order\Entities\VesselMasterEmail;
use Modules\Order\Entities\Order;
use Modules\Order\Entities\BunkerActivity;
use Modules\Order\Entities\BunkerActivityHistory;
use Modules\Order\Events\AmendPilotNotification;
use Modules\User\Entities\VesselMaster;
use Modules\User\Entities\Agent;
use Modules\User\Entities\Surveyor;
use Modules\Vessel\Entities\Vessel;
use Modules\Order\Repositories\OrderRepository;
use Modules\Order\Repositories\PilotageOrderRepository;
use Carbon\Carbon;

class PilotageOrderSeeder extends Seeder
{
    private $order;

    public function __construct(OrderRepository $order, PilotageOrderRepository $pilotageorder) {
        $this->order = $order;
        $this->pilotageorder = $pilotageorder;
    }

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get data from Json
        $json = File::get("database/data/pilotage_order.json");
        $datas = json_decode($json, true);

        // Sort data by TOADate ASC
        uasort($datas, function($a, $b) {
            return strtotime($a['pilotOrder']['TOADate']) - strtotime($b['pilotOrder']['TOADate']);
        });
        Log::info($datas);

        $results = [];
        foreach ($datas as $value) {
            $data = $value['pilotOrder'];
            // Check if already exist
            $pilotage_order =  PilotageOrder::where('OrderNumber', $data['OrderNumber'])->first();
            
            if($data['AdviceCode']=='N'){
                if(!$pilotage_order){
                    $result = $this->pilotageOrderCreate($data);
                } else {
                    $result = $this->pilotageOrderUpdate($data['OrderNumber'], $data);
                }
            } elseif($data['AdviceCode']=='A' || $data['AdviceCode']=='W'){
                $result = $this->pilotageOrderUpdate($data['OrderNumber'], $data);
            }

            // Push result to see the log in the end
            array_push($results, $result);

            if (isset($pilotage_order) && !empty($pilotage_order)) {
                $last_pilotage_order = $pilotage_order;
                $current_TOA = Carbon::parse($data['TOADate']);
                // Create vessel email
                $this->storeVesselEmail($data['OrderNumber'], $current_TOA);
            }
        }  
        Log::info('result');
        Log::info($results);      
    }

    public function pilotageOrderCreate($data)
    {
        $vessel_data = [
            'flag' => isset($data['VesselFlag']) ? $data['VesselFlag'] : '',
            'mmsi' => isset($data['MMSI']) ? $data['MMSI'] : '',
            'imo' => isset($data['IMO']) ? $data['IMO'] : '',
            'beam' => isset($data['Beam']) ? $data['Beam'] : '',
            'type' => isset($data['VesselType']) ? $data['VesselType'] : '',
            'status' => 'active',
            'VesselAbbrName' => isset($data['VesselAbbrName']) ? $data['VesselAbbrName'] : '',
            'VesselBowThruster' => isset($data['VesselBowThruster']) ? $data['VesselBowThruster'] : '',
            'VesselSternThruster' => isset($data['VesselSternThruster']) ? $data['VesselSternThruster'] : '',
            'VesselDraft' => isset($data['VesselDraft']) ? $data['VesselDraft'] : '',
            'VesselHeight' => isset($data['VesselHeight']) ? $data['VesselHeight'] : '',
            'VesselDisplacement' => isset($data['VesselDisplacement']) ? $data['VesselDisplacement'] : '',
            'VesselSubType' => isset($data['VesselSubType']) ? $data['VesselSubType'] : '',
            'name' => isset($data['VesselName']) ? $data['VesselName'] : '',
            'call_sign' => isset($data['VesselCallSign']) ? $data['VesselCallSign'] : '',
            'VesselVoyageNumber' => isset($data['VesselVoyageNumber']) ? $data['VesselVoyageNumber'] : '',
            'VesselSelfPropelling' => isset($data['VesselSelfPropelling']) ? $data['VesselSelfPropelling'] : '',
            'VesselCallN' => isset($data['VesselCallN']) ? $data['VesselCallN'] : '',
            'VesselGrt' => isset($data['VesselGrt']) ? $data['VesselGrt'] : '',
            'vessel_id' => isset($data['VesselID']) ? $data['VesselID'] : '',
            'loa' => isset($data['VesselLoa']) ? $data['VesselLoa'] : '',
            'TugServiceProvider' => isset($data['TugServiceProvider']) ? $data['TugServiceProvider'] : '',
            'TugNumber' => isset($data['TugNumber']) ? $data['TugNumber'] : ''
        ];

        ///--------- validating input fields value--------------///
        if (isset($data['Status']) && $data['Status'] != '') {
            $orderTypes = ['2', '4', '10', '12', '20', '22', '24', '25', '26', '28', '30'];
            if (in_array($data['Status'], $orderTypes) === false) {
                Log::error('Status is invalid');
            }
        }

        if(isset($data['CustomerContactEmail']) && !empty($data['CustomerContactEmail'])){
            $email =  $data['CustomerContactEmail'];
            $agent_id = Agent::where('email',$email)->first();

            if(!$agent_id) {
                if(isset($data['CustomerContactHp']) && !empty($data['CustomerContactHp'])){
                    $phoneNo = '+65'.$data['CustomerContactHp'];
                    $agent_id = Agent::where('phone_number', $phoneNo)->first();
                }
            }
        } else if(isset($data['CustomerContactHp']) && !empty($data['CustomerContactHp'])){
            $phoneNo = '+65'.$data['CustomerContactHp'];
            $agent_id = Agent::where('phone_number', $phoneNo)->first();
        }

        // Update vessel table with latest Mos table data
        if (isset($data['VesselID']) && !empty($data['VesselID'])) {
            $vessel = Vessel::where('vessel_id', $data['VesselID'])->first();
            if ($vessel) {
                Vessel::where('vessel_id', $vessel->vessel_id)->update($vessel_data);
            } else {
                Log::info('Mos Synchronize: New Vessel is created !!');
                Vessel::create($vessel_data);
            }
        }
        $data['vessel_id'] = isset($data['VesselID']) ? $data['VesselID'] : '';

        // Order Linking to oil terminal order
        try{
            Log::info('From mos controller: Trying linking pilotage order to existing terminal orders for existing agent...');
            $this->OrderLinkToTerminalOrders($data,$agent_id,$this->order);
            Log::info('Linking process done...');
        }catch (\Exception $e){
            Log::info('Problem with order linking...:-'.$e->getMessage());
        }

        // Update Agent Information
        if (isset($agent_id) && !empty($agent_id)) {
            $data['agent_id'] = $agent_id->user_id;
        }

        // send notification of "order notification" to oil terminator and surveyor
        $orderExistAlready = PilotageOrder::where('OrderNumber', $data['OrderNumber'])->first();
        if ($orderExistAlready) {
            Log::error('Mos Synchronize: Order already exist');
            Log::error('Pilotage Order Already exist with given Order Number, Please use Advice Code "A" or "W" to update');
        } else {
            //adding srtdate value to SrtDateOld
            $data['SRTDateOld'] = $data['SRTDate'];
            $data['ETBDate'] = trim($data['ETBDate']);
            // Create Pilotage Order
            $pilotage_order_data = [
                'vessel_id' => $data['VesselID'],
                'OrderNumber' => $data['OrderNumber'],
                'CustAccount' => $data['CustAccount'],
                'JobType' => $data['JobType'], 
                'PilotJobType' => $data['PilotJobType'],
                'EventAlertIndicator' => $data['EventAlertIndicator'],
                'TOADate' => $data['TOADate'],
                'CSTDate' => $data['CSTDate'],
                'SRTDate' => $data['SRTDate'],
                'ETBDate' => $data['ETBDate'],
                'PresentBerthAlongside' => $data['PresentBerthAlongside'],
                'RequiredBerthAlongside'=> $data['RequiredBerthAlongside'],
                'Remarks' => $data['Remarks'],
                'LocationFrom' => $data['LocationFrom'],
                'LocationTo' => $data['LocationTo'],
                'LocationFromDescription' => $data['LocationFromDescription'],
                'LocationToDescription' => $data['LocationToDescription'],
                'SourceParty' => $data['SourceParty'],
                'SourceCommsMode' => $data['SourceCommsMode'],
                'Status' => $data['Status'],
                'AdviceCode' => $data['AdviceCode'],
                'TransReason' => $data['TransReason'],
                'TransType' => $data['TransType'],
                'DeleteReason' => $data['DeleteReason'],
                'WaiverCode' => $data['WaiverCode'],
                'TugBillCompany' => $data['TugBillCompany'],
                'TugServiceProvider' => $data['TugServiceProvider'],
                'TugBillAccountType' => $data['TugBillAccountType'],
                'TugBillAccount' => $data['TugBillAccount'],
                'MinPilotLicense' => $data['MinPilotLicense'],
                'CustomerContactName' => $data['CustomerContactName'],
                'CustomerContactHp' => $data['CustomerContactHp'],
                'CustomerContactEmail' => $data['CustomerContactEmail'],
                'CustContractNumber' => $data['CustContractNumber'],
                'CustomerPoNumber' => $data['CustomerPoNumber'],
                'VesselArrCfmCode' => $data['VesselArrCfmCode'],
                'HoldBillIndicator' => $data['HoldBillIndicator'],
                'PilotCount' => $data['PilotCount'],
                'agent_id' => $data['agent_id'],
                'VesselVoyageNumber' => $data['VesselVoyageNumber'],
                'AssistByTowVessel' => $data['AssistByTowVessel'],
                'AICGranted' => $data['AICGranted'],
                'QuarantineRequired' => $data['QuarantineRequired'],
                'SpecialTugRequest' => $data['SpecialTugRequest'],
                'TugUnberthingNumber' => $data['TugUnberthingNumber'],
                'TugNumber' => $data['TugNumber'],
                'JobDuration' => $data['JobDuration'],
                'VesselArrivalDirection' => $data['VesselArrivalDirection'],
                'VesselSelfPropelling' => $data['VesselSelfPropelling'],
                'VesselCallN' => $data['VesselCallN']
            ];
            $pilotageCreate =  $this->pilotageorder->create($pilotage_order_data);
            
            if(!$pilotageCreate) {
                Log::error('Mos Synchronize: Pilotage Order Number is not able to create by server');
            }
        }

        //Set Vessel pilot order
        if(isset($data['VesselID']) && isset($data['LocationFrom']) && isset($data['LocationTo']) &&  isset($data['SRTDate']) && isset($data['CSTDate']) && !empty($data['SRTDate']) && !empty($data['CSTDate'])){
            $vesselInfo = Vessel::where('vessel_id', $data['VesselID'])->first();
            $boarding_ground_list = config('asgard.order.status.boarding_ground');
            if (in_array($data['LocationFrom'], $boarding_ground_list))
            {
                $vpcNewInfo = [];
                $vpcNewInfo['order_pilot_id'] = $data['OrderNumber'];
                if(isset($vesselInfo) && !empty($vesselInfo)) {
                    $vpcNewInfo['vessel_call_sign'] = $vesselInfo->call_sign;
                    $vpcNewInfo['vessel_name'] = $vesselInfo->name;
                    $vpcNewInfo['vessel_id'] = $vesselInfo->vessel_id;
                    $vpcNewInfo['location_from'] =  strtoupper($data['LocationFrom']);
                    $vpcNewInfo['location_to'] = $data['LocationTo'];
                    $vpcNewInfo['vessel_arrival_time'] = $data['SRTDate'];
                    $vpcNewInfo['vessel_pilot_cst'] = $data['CSTDate'];
                    $vpcNewInfo['vessel_cst_time_old'] = $data['CSTDate'];
                    $vpcNewInfo['vessel_confirm_code'] = $data['VesselArrCfmCode'];
                    $vpcNewInfo['status'] = config('asgard.order.status.pilotage_status.'.$data['Status']);
                    $vesselPilotOrder = VesselPilotOrder::create($vpcNewInfo);
                    if (!$vesselPilotOrder) {
                        Log::error('Mos Synchronize: Failed to create vessel pilot order');
                    }
                }
            }
        }

        //-----Now check if there is a bunker activity for this order--------------//
        $activities = BunkerActivity::where(['OrderNumber'=>$data['OrderNumber'], 'is_order_linked'=>0])->get();
        $pilotageorder = PilotageOrder::where('OrderNumber', $data['OrderNumber'])->first();

        if($activities && count($activities)>0){
            //update for all activities
            foreach($activities as $activity ){
                BunkerActivity::where('id', $activity->id)->update(['is_order_linked'=>1]);
            }
            //todo: pending task which was not completed during activity creation without order exist
            //update agent info in activity
            //send notifications to users
            Log::info('Going to sync pending task of activity...');
            $activity_count = count($activities);
            $key= 0;
            //using recursive function
            $this->doActivitySyncPendingTask($pilotageorder,$activities[$key],$activities,$key,$activity_count);
            Log::info('sync pending task of activity done');
        }
        if (isset($data['LocationTo']) && !empty($data['LocationTo'])){
            BunkerActivity::where(['OrderNumber'=>$data['OrderNumber']])->update(['ActivityLocation' => $data['LocationTo']]);
        }

        Log::info('Mos Synchronize: pilot order created');
        $result = ['order_number' => $data['OrderNumber'], 'msg_type' => 'Success', 'msg' => 'Pilotage Order Created Successfully'];
        return $result;
    }

    public function doActivitySyncPendingTask($pilotageorder,$activity,$activities,$key,$count) {
        if(!isset($pilotageorder->agent_id) || (isset($pilotageorder->agent_id)&& $pilotageorder->agent_id == 0)){
            return;
        }
        //--------Updating agent info for bunker activity------------//
        $agent_id = $pilotageorder->agent_id;
        //create agent activity for bunker activity with pending status
        $agent_activity['bunker_activity_id'] = $activity->id;
        $agent_activity['user_id'] = $agent_id;
        $agent_activity['status'] = P_BAG_ACK;
        BunkerAgentActivity::create($agent_activity);
        // update in bunker activity table
        $bc_update = BunkerActivity::where(['id'=>$activity->id])->update(['agent_id'=>$agent_id]);
        //----Save Action in bunker_activity_history_table
        //----Agent acknowledge sent/pending----//
        $activity_history['bunker_activity_id']= $activity->id;
        $activity_history['status']= P_BAG_ACK;
        $activity_history['user_type']= 'agent';
        BunkerActivityHistory::create($activity_history);

        //recurse this function for total activities
        $key++;
        if($key<$count){
            $this->doActivitySyncPendingTask($pilotageorder,$activities[$key],$activities,$key,$count);
        }
    }

    public function pilotageOrderUpdate($id, $data)
    {
        $data['AdviceCode'] = 'A';
        $data['vessel_id'] = $data['VesselID'];
        $vessel_data = [
            'flag' => isset($data['VesselFlag']) ? $data['VesselFlag'] : '',
            'mmsi' => isset($data['MMSI']) ? $data['MMSI'] : '',
            'imo' => isset($data['IMO']) ? $data['IMO'] : '',
            'beam' => isset($data['Beam']) ? $data['Beam'] : '',
            'type' => isset($data['VesselType']) ? $data['VesselType'] : '',
            'status' => 'active',
            'VesselAbbrName' => isset($data['VesselAbbrName']) ? $data['VesselAbbrName'] : '',
            'VesselBowThruster' => isset($data['VesselBowThruster']) ? $data['VesselBowThruster'] : '',
            'VesselSternThruster' => isset($data['VesselSternThruster']) ? $data['VesselSternThruster'] : '',
            'VesselDraft' => isset($data['VesselDraft']) ? $data['VesselDraft'] : '',
            'VesselHeight' => isset($data['VesselHeight']) ? $data['VesselHeight'] : '',
            'VesselDisplacement' => isset($data['VesselDisplacement']) ? $data['VesselDisplacement'] : '',
            'VesselSubType' => isset($data['VesselSubType']) ? $data['VesselSubType'] : '',
            'vessel_id' => isset($data['VesselID']) ? $data['VesselID'] : '',
            'name' => isset($data['VesselName']) ? $data['VesselName'] : '',
            'call_sign' => isset($data['VesselCallSign']) ? $data['VesselCallSign'] : '',
            'VesselVoyageNumber' => isset($data['VesselVoyageNumber']) ? $data['VesselVoyageNumber'] : '',
            'VesselSelfPropelling' => isset($data['VesselSelfPropelling']) ? $data['VesselSelfPropelling'] : '',
            'VesselCallN' => isset($data['VesselCallN']) ? $data['VesselCallN'] : '',
            'VesselGrt' => isset($data['VesselGrt']) ? $data['VesselGrt'] : '',
            'loa' => isset($data['VesselLoa']) ? $data['VesselLoa'] : '',
            'TugServiceProvider' => isset($data['TugServiceProvider']) ? $data['TugServiceProvider'] : '',
            'TugNumber' => isset($data['TugNumber']) ? $data['TugNumber'] : ''
        ];

        ///--------- validating input fields value--------------///
        if (isset($data['Status']) && $data['Status'] != '') {
            $orderTypes = ['2', '4', '10', '12', '20', '22', '24', '25', '26', '28', '30'];
            if (in_array($data['Status'], $orderTypes) === false) {
                Log::error('Status is invalid');
            }
        }

        //--------------validating ends---------------//
        if(isset($data['CustomerContactEmail']) && !empty($data['CustomerContactEmail'])){
            $email =  $data['CustomerContactEmail'];
            $agent_id = Agent::where('email',$email)->first();
            if(!$agent_id){
                if(isset($data['CustomerContactHp']) && !empty($data['CustomerContactHp'])){
                    $phoneNo = '+65'.$data['CustomerContactHp'];
                    $agent_id = Agent::where('phone_number', $phoneNo)->first();
                }
            }
        } else if(isset($data['CustomerContactHp']) && !empty($data['CustomerContactHp'])){
            $phoneNo = '+65'.$data['CustomerContactHp'];
            $agent_id = Agent::where('phone_number', $phoneNo)->first();
        }
        if(isset($agent_id)){
            $data['agent_id'] = $agent_id->user_id;
        }
        // Update vessel table with latest Mos table data
        if (isset($data['VesselID']) && !empty($tdata['VesselID'])) {
            $vessel = Vessel::where('vessel_id', $data['VesselID'])->first();
            if ($vessel_id) {
                Vessel::where('vessel_id', $vessel->vessel_id)->update($vessel_data);
            } else {
                Log::error('Mos Synchronize: New Vessel is created !!');
                Vessel::create($vessel_data);
            }
        }

        $pilotage = PilotageOrder::where('OrderNumber', $id)->first();
        if (!$pilotage) {
            Log::error('Mos Synchronize: Pilotage Order Number is deleted or not exist in database for update');
            Log::error('Pilotage Order Number is deleted or not exist in database for update, Please Use Advice code = N to create it first');
        }

        $pilotage_order = $pilotage;

        // Order Linking to oil terminal order
        try{
            Log::info('In update order: Trying linking pilotage order to existing terminal orders for existing agent...');
            $this->OrderLinkToTerminalOrdersAtUpdate($data,$agent_id,$this->order,$pilotage_order, $data);
            Log::info('Linking process done...');
        }catch (\Exception $e){
            Log::info('Problem with order linking...:-'.$e->getMessage());
        }

        $pilotage = PilotageOrder::where('OrderNumber', $id)->first();
        $data['ETBDate'] = trim($data['ETBDate']);
        $pilotage_order_data = [
            'vessel_id' => $data['VesselID'],
            'OrderNumber' => $data['OrderNumber'],
            'CustAccount' => $data['CustAccount'],
            'JobType' => $data['JobType'], 
            'PilotJobType' => $data['PilotJobType'],
            'EventAlertIndicator' => $data['EventAlertIndicator'],
            'TOADate' => $data['TOADate'],
            'CSTDate' => $data['CSTDate'],
            'SRTDate' => $data['SRTDate'],
            'ETBDate' => $data['ETBDate'],
            'PresentBerthAlongside' => $data['PresentBerthAlongside'],
            'RequiredBerthAlongside'=> $data['RequiredBerthAlongside'],
            'Remarks' => $data['Remarks'],
            'LocationFrom' => $data['LocationFrom'],
            'LocationTo' => $data['LocationTo'],
            'LocationFromDescription' => $data['LocationFromDescription'],
            'LocationToDescription' => $data['LocationToDescription'],
            'SourceParty' => $data['SourceParty'],
            'SourceCommsMode' => $data['SourceCommsMode'],
            'Status' => $data['Status'],
            'AdviceCode' => $data['AdviceCode'],
            'TransReason' => $data['TransReason'],
            'TransType' => $data['TransType'],
            'DeleteReason' => $data['DeleteReason'],
            'WaiverCode' => $data['WaiverCode'],
            'TugBillCompany' => $data['TugBillCompany'],
            'TugServiceProvider' => $data['TugServiceProvider'],
            'TugBillAccountType' => $data['TugBillAccountType'],
            'TugBillAccount' => $data['TugBillAccount'],
            'MinPilotLicense' => $data['MinPilotLicense'],
            'CustomerContactName' => $data['CustomerContactName'],
            'CustomerContactHp' => $data['CustomerContactHp'],
            'CustomerContactEmail' => $data['CustomerContactEmail'],
            'CustContractNumber' => $data['CustContractNumber'],
            'CustomerPoNumber' => $data['CustomerPoNumber'],
            'VesselArrCfmCode' => $data['VesselArrCfmCode'],
            'HoldBillIndicator' => $data['HoldBillIndicator'],
            'PilotCount' => $data['PilotCount'],
            'agent_id' => $data['agent_id'],
            'VesselVoyageNumber' => $data['VesselVoyageNumber'],
            'AssistByTowVessel' => $data['AssistByTowVessel'],
            'AICGranted' => $data['AICGranted'],
            'QuarantineRequired' => $data['QuarantineRequired'],
            'SpecialTugRequest' => $data['SpecialTugRequest'],
            'TugUnberthingNumber' => $data['TugUnberthingNumber'],
            'TugNumber' => $data['TugNumber'],
            'JobDuration' => $data['JobDuration'],
            'VesselArrivalDirection' => $data['VesselArrivalDirection'],
            'VesselSelfPropelling' => $data['VesselSelfPropelling'],
            'VesselCallN' => $data['VesselCallN']
        ];
        $pilotage_update = PilotageOrder::where('id', $pilotage->id)->update($pilotage_order_data);
        if (!$pilotage_update) {
            Log::error('Mos Synchronize: Pilotage order could not update');
        }

        if (isset($data['LocationTo']) && !empty($data['LocationTo'])) {
            BunkerActivity::where(['OrderNumber'=>$data['OrderNumber']])->update(['ActivityLocation' => $data['LocationTo']]);
        }

        //-----Now check vessel voyage -----//
        $pilotage_order = PilotageOrder::where('OrderNumber', $id)->first();
        
        // Update Vpc pilot table
        if(isset($data['VesselID']) && isset($data['LocationFrom']) && isset($data['LocationTo']) && isset($data['CSTDate']) && isset($data['SRTDate']) && !empty($data['CSTDate']) && !empty($data['SRTDate']) && isset($id)){
            $vesselInfo = Vessel::where('vessel_id', $data['VesselID'])->first();
            $boarding_ground_list = config('asgard.order.status.boarding_ground');
            if (in_array($data['LocationFrom'], $boarding_ground_list))
            {
                $vessel_pilotage_order = VesselPilotOrder::where('order_pilot_id', $id)->first();
                if (isset($vessel_pilotage_order) && !empty($vessel_pilotage_order)) {
                    $vpcInfo = [];
                    $vpcInfo['mos_proposed_boarding_ground'] = $data['LocationFrom'];
                    $vpcInfo['location_from'] = strtoupper($data['LocationFrom']);
                    $vpcInfo['location_to'] = $data['LocationTo'];
                    $vpcInfo['mos_proposed_cst'] = $data['CSTDate'];
                    $vpcInfo['vessel_pilot_cst'] = $data['CSTDate'];
                    $vpcInfo['vessel_cst_time_old'] = $vessel_pilotage_order->vessel_pilot_cst;
                    $vpcInfo['vessel_arrival_time'] = $data['SRTDate'];
                    $vpcInfo['vessel_confirm_code'] = $data['VesselArrCfmCode'];
                    $vpcInfo['status'] = config('asgard.order.status.pilotage_status.'.$data['Status']);

                    // Update vessel information
                    $vpcInfo['vessel_id'] = $vesselInfo->vessel_id;
                    $vpcInfo['vessel_call_sign'] = $vesselInfo->call_sign;
                    $vpcInfo['vessel_name'] = $vesselInfo->name;
                    try {
                        $vesselPilotOrderUpdate = VesselPilotOrder::where('order_pilot_id', $id)->update($vpcInfo);
                        Log::info('Vessel Pilot Order table updated with order number is : '. strtoupper($data['OrderNumber']));
                    } catch (\Exception $e) {                    
                        Log::error('Mos Synchronize: Failed to update Vessel Pilotage order, error message : '.$e->getMessage());
                    }
                } else {
                    $vpcNewInfo = [];
                    $vpcNewInfo['order_pilot_id'] = $data['OrderNumber'];
                    $pilot_order = PilotageOrder::where('OrderNumber', $id)->first();
                    $old_cst = $data['CSTDate'];
                    if(isset($pilot_order) && !empty($pilot_order)){
                        $old_cst = $pilot_order->SRTDateOld;
                    }
                    if(isset($vesselInfo) && !empty($vesselInfo)) {
                        $vpcNewInfo['vessel_call_sign'] = $vesselInfo->call_sign;
                        $vpcNewInfo['vessel_name'] = $vesselInfo->name;
                        $vpcNewInfo['vessel_id'] = $vesselInfo->vessel_id;
                        $vpcNewInfo['location_from'] = strtoupper($data['LocationFrom']);
                        $vpcNewInfo['location_to'] = $data['LocationTo'];
                        $vpcNewInfo['vessel_arrival_time'] = $data['SRTDate'];
                        $vpcNewInfo['vessel_pilot_cst'] = $data['CSTDate'];
                        $vpcNewInfo['vessel_cst_time_old'] = $old_cst;
                        $vpcNewInfo['vessel_confirm_code'] = $data['VesselArrCfmCode'];
                        $vpcNewInfo['status'] = config('asgard.order.status.pilotage_status.'.$data['Status']);
                        $vesselPilotOrder = VesselPilotOrder::create($vpcNewInfo);
                        if (!$vesselPilotOrder) {
                            Log::error('Mos Synchronize: Failed to create vessel pilot order');
                        }
                    }
                }
            }
            else {
                // Remove Vesse pilot order when LocationFrom is not from boarding ground
                $now = (new \DateTime("now", new \DateTimeZone(Setting::get('core::default-timezone'))))->format('Y-m-d H:i:s');
                $id_now = $id."_".$now;
                $vessel_name_now = $data['VesselName']."_".$now;
                VesselPilotOrder::where('order_pilot_id', $id)->update(['order_pilot_id' => $id_now,'vessel_name' => $vessel_name_now]);
                try {
                    VesselPilotOrder::where('order_pilot_id', $id_now)->delete();
                    Log::info('Vessel Pilot Order table has been removed because of location from is : '. strtoupper($data['LocationFrom']));
                } catch (\Exception $e) {                    
                    Log::error('Mos Synchronize: Failed to Delete Vessel Pilotage order, error message : '.$e->getMessage());
                }
            }
        }
        $result = ['order_number' => $data['OrderNumber'], 'msg_type' => 'Success', 'msg' => 'Pilotage Order Updated Successfully'];
        return $result;
    }

    private function storeVesselEmail($order_id, $sent_date) {
        $vpc_data  = VesselPilotOrder::where('order_pilot_id',$order_id)->first();
        $vessel_id = $vpc_data->vessel_id;
        $call_sign = $vpc_data->vessel_call_sign;
        $order_number = $order_id;
        $cst_date =  $vpc_data->vessel_pilot_cst;
        $usr_vessel = VesselMaster::where(['vessel_id' => $vessel_id])->first();
        if(isset($usr_vessel) && !empty($usr_vessel)){
            $vessel_master_id = $usr_vessel->id;
        }else{
            $vessel_master_id = 0;
            Log::info("Vessel Master Not Found");
        }
        // Change in pilot boarding time after BGPAN is acknowledged - Container terminal change timing and pilot cst is changed
        $input_data = array(
            'order_number' => $order_number,
            'vessel_master_id' => $vessel_master_id,
            'vessel_id' => $vessel_id,
            'call_sign' => $call_sign,
            'email_title' => "CST_CHANGE",
            'status' => "active",
            'cst_date' => $cst_date,
            'email_date' => $sent_date
        );

        // Check if vessel master email created with same current cst and title bgpan amend
        $vm_email_sent_bgpan_amend = VesselMasterEmail::where(['order_number'=> $order_number, 'email_title' => "BGPAN_AMEND", 'cst_date' => $cst_date])->first();

        if(empty($vm_email_sent_bgpan_amend)) {
            $last_vm_email = VesselMasterEmail::where('order_number', $order_number)->where('vessel_id', $vessel_id)->where('vessel_master_id', $vessel_master_id)->where('email_title', 'CST_CHANGE')->where('cst_date', $cst_date)->orderBy('updated_at', 'desc')->first();
            if (empty($last_vm_email)) {
                // Create Vessel Master Email
                VesselMasterEmail::create($input_data);
                // Update vessel_time_old with current vessel pilot cst 
                VesselPilotOrder::where('order_pilot_id', $vpc_data->order_pilot_id)->update(['vessel_cst_time_old' => $vpc_data->vessel_pilot_cst]);
                Log::info('Vessel Master Email created');
            }
        }
        else {
            Log::info('Vessel Master Email exists');
        }
    }

    public function OrderLinkToTerminalOrders($data,$agent_id, $order){
        Log::info('Linking started...');
        $this->order = $order;        
        $req = $data;
        $data['VesselID'] = $data['vessel_id'];
        Log::info('Create Request:');
        Log::info($req);
        // Order Linking  to oil terminal order
        if (isset($data['VesselID']) && (isset($agent_id) && $agent_id!=null) && isset($req['LocationTo']) && !empty($req['LocationTo'])  && isset($req['PilotJobType']) && !empty($req['PilotJobType']))  {
            if($req['PilotJobType'] === 'BE' || $req['PilotJobType'] === 'UB' || $req['PilotJobType'] === 'SH'){
                if($req['PilotJobType'] === 'UB' && isset($req['LocationFrom']) && !empty($req['LocationFrom']) ){
                    $location_id = Location::where('location_c', '=', $req['LocationFrom'])->first();
                }else{
                    $location_id = Location::where('location_c', '=', $req['LocationTo'])->first();
                }
                $order_linked = [];
                if($location_id) {
                    $berth_id = Berth::where('location_id', $location_id->id)->first();
                    $order_type = $req['PilotJobType'] === 'BE' ? 'berthing' : ($req['PilotJobType'] === 'UB' ? 'unberthing' : 'shifting');
                    if($berth_id) {
                        $vessel = Vessel::where('vessel_id',$data['VesselID'])->first();
                        $order_status = false;

                        $getOTOrders = Order::where('berth_id', $berth_id->id)->where('archived', 0)->where('vessel_id', $vessel->id)->where('agent_id',$agent_id->user_id)->where('type', $order_type)->where('amend', '')->where('pilotage_order_id', '')->get();
                        
                        if(count($getOTOrders) > 0){
                            // Get minimum difference between order pob_date and pilotage order SRT date
                            $getOrder = $getOTOrders[0];

                            foreach($getOTOrders as $ot_order){
                                $pobDate1 = date("Y-m-d H:i:s", $getOrder->pob_date);
                                $pobDateNew1 = new \DateTime($pobDate1, new \DateTimeZone(Setting::get('core::default-timezone')));
                                $pobDateNew1 = $pobDateNew1->format('Y-m-d H:i');

                                $pobDate2 = date("Y-m-d H:i:s", $ot_order->pob_date);
                                $pobDateNew2 = new \DateTime($pobDate2, new \DateTimeZone(Setting::get('core::default-timezone')));
                                $pobDateNew2 = $pobDateNew2->format('Y-m-d H:i');

                                $pobDateNew1 = strtotime($pobDateNew1);
                                $pobDateNew2 = strtotime($pobDateNew2);
                                $SRTdate = strtotime($data['SRTDate']);
                                $srtDate1 = abs($pobDateNew1 - $SRTdate);
                                $srtDate2 = abs($pobDateNew2 - $SRTdate);

                                if($srtDate2 < $srtDate1){
                                    $getOrder = $ot_order;
                                }                               
                            }
                            if ($getOrder){
                                $order_status = Order::where('id', $getOrder->id)->update(['pilotage_order_id'=> $data['OrderNumber']]);
                            }
                        }                      

                        $getOrders = Order::where('berth_id', $berth_id->id)->where('archived', 0)->where('display', 1)->where('vessel_id', $vessel->id)->where('type', $order_type)->where('amend', '')->where('agent_id',$agent_id->user_id)->get();

                        if($getOrders && count($getOrders)>0){
                            foreach($getOrders as $getOrder){
                                if(isset($getOrder)&& !empty($getOrder) && isset($req['CSTDate']) && !empty($req['CSTDate'])  ){
                                    $updtPilotcst = 0;
                                    if($getOrder->status === P_PILOT_CST){
                                        $datestring = explode(" ",$req['CSTDate']);
                                        $date_cst = $datestring[0];
                                        $time_cst = $datestring[1];
                                        $order_number = $req['OrderNumber'];
                                        //$updtPilotcst = $this->confirmPilot($getOrder->id,$date_cst,$time_cst,$order_number);
                                        $updtPilotcst = $this->confirmPilot($getOrder,$date_cst,$time_cst,$order_number);
                                        if($updtPilotcst){
                                            $order_linked[] = $getOrder->id;
                                        }
                                    }
                                }
                            }
                        }
                        if($order_status) {
                            Log::info('Mos Synchronize: Order linking Updated');
                        }
                    }
                }
            }
        }
    }

    public function OrderLinkToTerminalOrdersAtUpdate($data,$agent_id, $order,$pilotage_order, $req){
        Log::info('Update Request:');
        Log::info($req);
        if (isset($req['CSTDate']) && !empty($req['CSTDate'])) {
            if ($pilotage_order->CSTDate !== $req['CSTDate']) {
                if (isset($data['VesselID']) && isset($agent_id) && !empty($agent_id) && isset($req['LocationTo']) && !empty($req['LocationTo'])  && isset($req['PilotJobType']) && !empty($req['PilotJobType']))  {
                    if($req['PilotJobType'] === 'BE' || $req['PilotJobType'] === 'UB' || $req['PilotJobType'] === 'SH'){
                        if($req['PilotJobType'] === 'UB' && isset($req['LocationFrom']) && !empty($req['LocationFrom']) ){
                            $location_id = Location::where('location_c', '=', $req['LocationFrom'])->first();
                        }else{
                            $location_id = Location::where('location_c', '=', $req['LocationTo'])->first();
                        }
                        $order_linked = [];
                        if ($location_id) {
                            $berth_id = Berth::where('location_id', $location_id->id)->first();
                            $order_type = $req['PilotJobType'] === 'BE' ? 'berthing' : ($req['PilotJobType'] === 'UB' ? 'unberthing' : 'shifting');
                            if ($berth_id) {
                                $vessel = Vessel::where('vessel_id',$data['VesselID'])->first();
                                $getOrders = Order::where('berth_id', $berth_id->id)->where('archived', 0)->where('display', 1)->where('vessel_id', $vessel->id)->where('type', $order_type)->where('agent_id',$agent_id->user_id)->where('amend', '')->get();
                                if($getOrders && count($getOrders)>0)
                                    foreach($getOrders as $getOrder) {
                                        // Confirm pilot
                                        if (isset($getOrder) && !empty($getOrder) && isset($req['CSTDate']) && !empty($req['CSTDate'])) {
                                            if ($getOrder->status === P_PILOT_CST) {
                                                $datestring = explode(" ", $req['CSTDate']);
                                                $date_cst = $datestring[0];
                                                $time_cst = $datestring[1];
                                                $order_number = $req['OrderNumber'];

                                                $updtPilotcst = $this->confirmPilot($getOrder, $date_cst, $time_cst, $order_number);
                                                if ($updtPilotcst) {
                                                    $order_linked[] = $getOrder->id;
                                                }
                                            }
                                            // amend pilot
                                            if (in_array($getOrder->status, [PILOT_CST, P_SV_ACK, SV_ACK, P_SV_BOARD])) {
                                                $datestring = explode(" ", $req['CSTDate']);
                                                $date_cst = $datestring[0];
                                                $time_cst = $datestring[1];
                                                $order_number = $req['OrderNumber'];
                                                $amended = $this->amendPilot($getOrder->id, $date_cst, $time_cst, $order_number);
                                            }
                                        }
                                    }

                            }
                        }
                    }
                }
            }
        }
    }

    public function confirmPilot($ord,$date,$time,$order_number)
    {
        $oldOrd = $ord;
        $order = Order::find($ord->id);
        $oldStatus = $order->status = PILOT_CST;
        $updatedTime = $date . " " . $time . " Asia/Singapore";
        $order->pob_date_surveyor = strtotime($updatedTime);
        $res = $order->save();
        $pilotTime = strtotime($date . " " . $time . " Asia/Singapore");
        $pilotMso = $order_number;
        $this->order->updateStatusAndActivity($order, [
            'pilot_cst_time' => $pilotTime,
            'pilot_mos' => $pilotMso
        ]);

        //Update last order
        $this->order->updateLastOrderForWebFromApi($order->oil_terminator_id, $order->vessel_id, $order->group);
        $this->order->updateNextLastOrderForAgent($order->agent_id, $order->vessel_id, $order->group);
        $this->order->updateNextLastOrderForSurveyor($order->surveyor_id, $order->vessel_id, $order->group);

        //Send notification
        if($res && (isset($oldOrd) && $oldOrd->status== P_PILOT_CST)){
            return true;
        }
        return false;
    }

    public function amendPilot($id,$date,$time,$order_number)
    {
        $order = Order::find($id);
        $oldStatus = $order->status = PILOT_CST;
        $updatedTime = $date . " " . $time . " Asia/Singapore";
        $order->pob_date_surveyor = strtotime($updatedTime);
        $order->save();

        //Update new status
        $pilotTime = strtotime($date . " " . $time . " Asia/Singapore");
        $pilotMso = $order_number;
        $this->order->updateStatusAndActivity($order, [
            'pilot_cst_time' => $pilotTime,
            'pilot_mos' => $pilotMso
        ]);

        //Update last order
        $this->order->updateLastOrderForWebFromApi($order->oil_terminator_id, $order->vessel_id, $order->group);
        $this->order->updateNextLastOrderForAgent($order->agent_id, $order->vessel_id, $order->group);
        $this->order->updateNextLastOrderForSurveyor($order->surveyor_id, $order->vessel_id, $order->group);
    }
}
