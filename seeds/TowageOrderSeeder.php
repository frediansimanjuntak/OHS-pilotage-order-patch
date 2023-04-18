<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Modules\Berth\Entities\Berth;
use Modules\Berth\Entities\Location;
use Modules\Order\Entities\TowageOrder;
use Modules\Order\Entities\TowageOrderActivity;
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
use Modules\Order\Repositories\TowageOrderRepository;
use App\Common\Helper;
use Carbon\Carbon;

class TowageOrderSeeder extends Seeder
{
    protected $order;
    protected $towageOrder;

    public function __construct(OrderRepository $order, TowageOrderRepository $towageOrder) {
        $this->order = $order;
        $this->towageOrder = $towageOrder;
    }

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        
        // Get data from Json
        $json = File::get("database/data/towage_order.json");
        $datas = json_decode($json, true);

        Log::info('Towage order seeder started');
        Log::info('total data : '.count($datas).' data');

        
        foreach ($datas as $key=>$value) {
            $data = $value['towageOrder'];
            Log::info('=======================================');
            Log::info('Index : '. $key);
            Log::info('Towage Order Number : '. $data['OrderNumber']);
            
            //Switch according to advice code
            if($data['AdviceCode']=='N' || $data['AdviceCode']=='A' || $data['AdviceCode']=='W'){
                //Check if already exist
                $prev_towage_order =  TowageOrder::where('OrderNumber',$data['OrderNumber'])->first();
                if(!$prev_towage_order){
                    $this->towageOrderCreate($data);
                }
                $this->towageOrderUpdate($data['OrderNumber'], $data, $prev_towage_order);

            }
            elseif($data['AdviceCode']=='D' || $data['AdviceCode']=='Z'){
                $this->towageOrderDelete($data['OrderNumber'], $data);
            }
        }
    }

    private function towageOrderCreate($data)
    {
        $start_do = Carbon::now();
        $vessel_data = [
            'flag' => isset($data['VesselFlag']) ? $data['VesselFlag'] : null,
            'mmsi' => isset($data['MMSI']) ? $data['MMSI'] : null,
            'imo' => isset($data['IMO']) ? $data['IMO'] : null,
            'beam' => isset($data['Beam']) ? $data['Beam'] : null,
            'type' => isset($data['VesselType']) ? $data['VesselType'] : null,
            'status' => 'active',
            'VesselAbbrName' => isset($data['VesselAbbrName']) ? $data['VesselAbbrName'] : null,
            'VesselBowThruster' => isset($data['VesselBowThruster']) ? $data['VesselBowThruster'] : null,
            'VesselSternThruster' => isset($data['VesselSternThruster']) ? $data['VesselSternThruster'] : null,
            'VesselDraft' => isset($data['VesselDraft']) ? $data['VesselDraft'] : null,
            'VesselHeight' => isset($data['VesselHeight']) ? $data['VesselHeight'] : null,
            'VesselDisplacement' => isset($data['VesselDisplacement']) ? $data['VesselDisplacement'] : null,
            'VesselSubType' => isset($data['VesselSubType']) ? $data['VesselSubType'] : null,
            'name' => isset($data['VesselName']) ? $data['VesselName'] : null,
            'call_sign' => isset($data['VesselCallSign']) ? $data['VesselCallSign'] : null,
            'VesselVoyageNumber' => isset($data['VesselVoyageNumber']) ? $data['VesselVoyageNumber'] : null,
            'VesselSelfPropelling' => isset($data['VesselSelfPropelling']) ? $data['VesselSelfPropelling'] : null,
            'VesselCallN' => isset($data['VesselCallN']) ? $data['VesselCallN'] : null,
            'VesselGrt' => isset($data['VesselGrt']) ? $data['VesselGrt'] : null,
            'vessel_id' => isset($data['VesselID']) ? $data['VesselID'] : null,
            'loa' => isset($data['VesselLoa']) ? $data['VesselLoa'] : null,
            'TugServiceProvider' => isset($data['TugServiceProvider']) ? $data['TugServiceProvider'] : null,
            'TugNumber' => isset($data['TugNumber']) ? $data['TugNumber'] : null
        ];

        ///--------- validating input fields value--------------///
        if (isset($data['Status']) && $data['Status'] != '') {
            $orderTypes = ['2', '4', '10', '12', '20', '22', '24', '25', '26', '28', '30'];
            if (in_array($data['Status'], $orderTypes) === false) {
                Log::error('Status is invalid');
            }
        }

        //--------------validating ends---------------//

       if(isset($data['CustomerContactHp']) && !empty($data['CustomerContactHp'])){
            $phoneNo = '+65'.$data['CustomerContactHp'];
            $agent_id = Agent::where('phone_number', $phoneNo)->first();
            if(!$agent_id){
                Log::error('Not Found agent phone number: '.$phoneNo);
            }
        }

        // Update vessel table with latest Mos table data
        if (isset($data['VesselID']) && !empty($data['VesselID'])) {
            $this->updateVesselInfo($data['VesselID'], $vessel_data);
        }
        // Update Agent Information
        if (isset($agent_id) && !empty($agent_id)) {
            $data['agent_id'] = $agent_id->user_id;
        }

        // check order exist
        $orderExistAlready = TowageOrder::where('OrderNumber', $data['OrderNumber'])->first();
        if ($orderExistAlready) {
            Log::error('Mos Synchronize: Towage Order already exist');
            Log::error('Towage Order already exist with given Order Number, Please use Advice Code "A" or "W" to update');
        } else {
            $data['ETBDate'] = trim($data['ETBDate']);
            // Create towage Order & Activity
            $towageCreate =  TowageOrder::create($data);
            if($towageCreate && $data['Status'] == 20 ) {
                $dataActivity = array(
                    'OrderNumber' => $data['OrderNumber'], 
                    'CSTDate' => $data['CSTDate'], 
                    'SRTDate' => $data['SRTDate'],
                );
               TowageOrderActivity::create($dataActivity);
            }
            
            if(!$towageCreate) {
                Log::error('Mos Synchronize: Towage Order Number is not able to create by server');
            }
        }

        $end_do = Carbon::now();        
        $totalDuration = $start_do->diffInSeconds($end_do);
        
        Log::info('Mos Synchronize: towage order is successfully created with duration (sec): '. $totalDuration );        
    }

    private function towageOrderUpdate($orderNumber, $data, $prev_towage_order)
    {        
        $start_do = Carbon::now();
        $data['AdviceCode'] = 'A';
        $vessel_data = [
            'flag' => isset($data['VesselFlag']) ? $data['VesselFlag'] : null,
            'mmsi' => isset($data['MMSI']) ? $data['MMSI'] : null,
            'imo' => isset($data['IMO']) ? $data['IMO'] : null,
            'beam' => isset($data['Beam']) ? $data['Beam'] : null,
            'type' => isset($data['VesselType']) ? $data['VesselType'] : null,
            'status' => 'active',
            'VesselAbbrName' => isset($data['VesselAbbrName']) ? $data['VesselAbbrName'] : null,
            'VesselBowThruster' => isset($data['VesselBowThruster']) ? $data['VesselBowThruster'] : null,
            'VesselSternThruster' => isset($data['VesselSternThruster']) ? $data['VesselSternThruster'] : null,
            'VesselDraft' => isset($data['VesselDraft']) ? $data['VesselDraft'] : null,
            'VesselHeight' => isset($data['VesselHeight']) ? $data['VesselHeight'] : null,
            'VesselDisplacement' => isset($data['VesselDisplacement']) ? $data['VesselDisplacement'] : null,
            'VesselSubType' => isset($data['VesselSubType']) ? $data['VesselSubType'] : null,
            'vessel_id' => isset($data['VesselID']) ? $data['VesselID'] : null,
            'name' => isset($data['VesselName']) ? $data['VesselName'] : null,
            'call_sign' => isset($data['VesselCallSign']) ? $data['VesselCallSign'] : null,
            'VesselVoyageNumber' => isset($data['VesselVoyageNumber']) ? $data['VesselVoyageNumber'] : null,
            'VesselSelfPropelling' => isset($data['VesselSelfPropelling']) ? $data['VesselSelfPropelling'] : null,
            'VesselCallN' => isset($data['VesselCallN']) ? $data['VesselCallN'] : null,
            'VesselGrt' => isset($data['VesselGrt']) ? $data['VesselGrt'] : null,
            'loa' => isset($data['VesselLoa']) ? $data['VesselLoa'] : null,
            'TugServiceProvider' => isset($data['TugServiceProvider']) ? $data['TugServiceProvider'] : null,
            'TugNumber' => isset($data['TugNumber']) ? $data['TugNumber'] : null
        ];

        ///--------- validating input fields value--------------///

        if (isset($data['Status']) && $data['Status'] != '') {
            $orderTypes = ['2', '4', '10', '12', '20', '22', '24', '25', '26', '28', '30'];
            if (in_array($data['Status'], $orderTypes) === false) {
                Log::error('Status is invalid');
            }
        }

        //--------------validating ends---------------//
        if(isset($data['CustomerContactHp']) && !empty($data['CustomerContactHp'])){
            $phoneNo = '+65'.$data['CustomerContactHp'];
            $agent_id = Agent::where('phone_number', $phoneNo)->first();
            if(!$agent_id){
                Log::error('Not Found agent phone number: '.$phoneNo);
            }
        }

         // Update Agent Information
         if (isset($agent_id) && !empty($agent_id)) {
            $data['agent_id'] = $agent_id->user_id;
        }
        // Update vessel table with latest Mos table data
        if (isset($data['VesselID']) && !empty($data['VesselID'])) {
            $this->updateVesselInfo($data['VesselID'], $vessel_data);
        }

        $towage_order = $prev_towage_order;
        if (!$towage_order) {
            Log::error('Mos Synchronize: Towage Order Number is deleted or not exist in database for update');
            Log::error('Towage Order Number is deleted or not exist in database for update, Please Use Advice code = N to create it first');
        }

        $towage = $this->towageOrder->findByAttributes(array('OrderNumber' => $orderNumber));
        $data['ETBDate'] = trim($data['ETBDate']);
    
        if(isset($towage->CSTDate)  &&  isset($towage->SRTDate)){
            if (($data['CSTDate'] !== $towage->CSTDate  || $data['SRTDate'] !== $towage->SRTDate) && $data['Status'] == 20 ){
                $towageServices = $towage->towageServices;
                if(count($towageServices) > 0){
                    foreach ($towageServices as $towageService) {
                        if(isset($towageService)){
                            $dataActivity = array(
                                'OrderNumber' => $data['OrderNumber'], 
                                'CSTDate' => $data['CSTDate'], 
                                'SRTDate' => $data['SRTDate'],
                                'towage_service_id' => $towageService->id, 
                                'towage_service_Sequence' => $towageService->Sequence, 
                                'towage_service_SRTDate' => $towageService->SRTDate
                            );
                        }        
                        TowageOrderActivity::create($dataActivity);
                    }
                } else {
                    if($data['SRTDate'] != $towage->SRTDate) {
                        $dataActivity = array(
                            'OrderNumber' => $data['OrderNumber'], 
                            'CSTDate' => $data['CSTDate'], 
                            'SRTDate' => $data['SRTDate'],
                        );
                        TowageOrderActivity::create($dataActivity);
                    }
                }
            }
        }
        $towage_update = $this->towageOrder->update($towage, $data);

        if (!$towage_update) {
            Log::error('Mos Synchronize: Towage order could not update');
        }
        
        $end_do = Carbon::now();        
        $totalDuration = $start_do->diffInSeconds($end_do);
        
        Log::info('Mos Synchronize: Towage order is successfully updated with duration (sec): '. $totalDuration );
    }

    private function towageOrderDelete($orderNumber, $data)
    {
        $start_do = Carbon::now();
        $del = $this->towageOrder->findByAttributes(array('OrderNumber' => $orderNumber));

        if (!$del) {
            Log::error('Mos Synchronize: Towage order deletion failed, not exist');
        }

        // Delete pilotage order
        $del->delete();

        $end_do = Carbon::now();        
        $totalDuration = $start_do->diffInSeconds($end_do);
        
        Log::info('Mos Synchronize: Towage order is successfully deleted with duration (sec): '. $totalDuration ); 
    }

    private function updateVesselInfo($vessel_id, $vessel_data) {
        Log::info('Vessel Info: update vessel id - '.$vessel_id);
        // Update Vessel Data
        $vessel = Vessel::where('vessel_id', $vessel_id)->first();
        if ($vessel) {
            try{
                Vessel::where('vessel_id', $vessel->vessel_id)->update($vessel_data);
                Log::info('Vessel Info: Vessel updated !!');
            }catch(\Exception $e){
                Log::error('Vessel Info: Update Vessel failed - '.$e->getMessage());
            }
        } else {
            try{
                Vessel::create($vessel_data);
                Log::info('Vessel Info: New Vessel is created !!');
            }catch(\Exception $e){
                Log::error('Vessel Info: Create Vessel failed - '.$e->getMessage());
            }
        }

        // Update Vessel Master Data
        $vessel_master_info = VesselMaster::where('vessel_id', $vessel_id)->get();
        if (count($vessel_master_info) > 0) {
            try{
                VesselMaster::where('vessel_id', $vessel_id)->update(['vessel_name'=>$vessel_data['name'], 'vessel_callsign' => $vessel_data['call_sign']]);
                Log::info('Vessel Info: Vessel Master updated !!');
            }catch(\Exception $e){
                Log::error('Vessel Info: Update Vessel Master failed - '.$e->getMessage());
            }
            
        }

        // Update Vessel Master Email Data
        $vessel_master_email = VesselMasterEmail::where('vessel_id', $vessel_id)->get();
        if (count($vessel_master_email) > 0) {
            try{
                VesselMasterEmail::where('vessel_id', $vessel_id)->update(['call_sign' => $vessel_data['call_sign']]);
                Log::info('Vessel Info: Vessel Master Email updated !!');
            }catch(\Exception $e){
                Log::error('Vessel Info: Update Vessel Master Email failed - '.$e->getMessage());
            }
        }

        // Update Order
        $vessel_pilot_order = VesselPilotOrder::where('vessel_id', $vessel_id)->get();
        if (count($vessel_pilot_order) > 0) {
            try{
                VesselPilotOrder::where('vessel_id', $vessel_id)->update(['vessel_name' => $vessel_data['name'], 'vessel_call_sign' => $vessel_data['call_sign']]);
                Log::info('Vessel Info: Vessel Pilot Order updated !!');
            }catch(\Exception $e){
                Log::error('Vessel Info: Update Vessel Pilot Order failed - '.$e->getMessage());
            }
        }
    }
}