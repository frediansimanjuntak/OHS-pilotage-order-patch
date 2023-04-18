<?php

use Illuminate\Database\Seeder;
use Modules\User\Entities\Agent;
use Modules\Order\Entities\TowageageOrder;
use Modules\Order\Entities\TowageOrderActivity;
use Modules\Order\Entities\Order;
use Modules\Order\Entities\TowageServiceInfo;
use Modules\Order\Repositories\TowageServiceRepository;
use Carbon\Carbon;

class TowageEventSeeder extends Seeder
{
    protected $towageService;

    public function __construct(TowageServiceRepository $towageService) {
        $this->towageService = $towageService;
    }
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get data from Json
        $json = File::get("database/data/towage_event.json");
        $datas = json_decode($json, true);
        
        foreach ($datas as $value) {
            $data = $value['towageService'];
            $data = array_filter($data);   
            //Switch according to AdviceCode code
            if($data['AdviceCode']=='N'){
                $this->eventsCreate($data);
            }elseif($data['AdviceCode']=='A' || $data['AdviceCode']=='W'){
                $this->eventsUpdate($data['OrderNumber'], $data);
            }elseif($data['AdviceCode']=='D' || $data['AdviceCode']=='Z'){
                $this->eventsDelete($data['OrderNumber'], $data);
            }
        }
    }

    public function eventsCreate($data) {
        $start_do = Carbon::now();
        $service = $this->towageService->getModel()->where('OrderNumber', $data['OrderNumber'])->where('Sequence', $data['Sequence'])->first();

        if ($service) {
            //go to update if already exist
            return $this->towageEventsUpdate($data['OrderNumber'], $data);
        } else {
            // Create towage service
            try {
                $towageService = $this->towageService->getModel()->firstOrCreate($data);
                $towageOrder = $towageService->towageOrder;

                if($towageService->SRTDate != $towageOrder->SRTDate) {
                    // Create activity
                    $dataTowageActivity = array(
                        'OrderNumber' => $towageOrder->OrderNumber,
                        'CSTDate' => $towageOrder->CSTDate,
                        'SRTDate' => $towageOrder->SRTDate,
                        'towage_service_id' => $towageService->id,
                        'towage_service_Sequence' => $towageService->Sequence,
                        'towage_service_SRTDate' => $towageService->SRTDate,
                    );
                    
                    TowageOrderActivity::create($dataTowageActivity);
                }

                $end_do = Carbon::now();        
                $totalDuration = $start_do->diffInSeconds($end_do);
                
                Log::info('Mos Synchronize: Towage Service is successfully created with duration (sec): '. $totalDuration );
            } catch (\Exception $e) {
                Log::error('Mos Synchronize: Towage Service creation failed - Server Error : '.$e->getMessage());
            }
        }      
    }
    
    public function eventsUpdate($towageOrder, $data){
        $start_do = Carbon::now();
        $towageServiceData =  $this->towageService->getModel()->where(array('OrderNumber'=>$towageOrder,'Sequence'=>$data['Sequence']))->latest()->first();
        
        if(!$towageServiceData){
            Log::error('Mos Synchronize: Towage Service not found');
        }
        // Update Towage Service
        try {
            $this->towageService->getModel()->where(array('OrderNumber'=>$towageOrder,'Sequence'=>$data['Sequence']))->update($data);
            
            $towageService = $this->towageService->getModel()->where(array('OrderNumber'=>$towageOrder,'Sequence'=>$data['Sequence']))->first();
            $towageOrder = $towageService->towageOrder;

            // Create activity
            if($towageServiceData->SRTDate != $towageService->SRTDate) {
                $dataTowageActivity = array(
                    'OrderNumber' => $towageOrder->OrderNumber,
                    'CSTDate' => $towageOrder->CSTDate,
                    'SRTDate' => $towageOrder->SRTDate,
                    'towage_service_id' => $towageService->id,
                    'towage_service_Sequence' => $towageService->Sequence,
                    'towage_service_SRTDate' => $towageService->SRTDate,
                );
                
                TowageOrderActivity::create($dataTowageActivity);
            }            

            $end_do = Carbon::now();        
            $totalDuration = $start_do->diffInSeconds($end_do);
            
            Log::info('Mos Synchronize: Towage Service is successfully updated with duration (sec): '. $totalDuration );
        } catch (\Exception $e) {      
            Log::error('Mos Synchronize: Towage Service updating failed - Server Error  : '.$e->getMessage());
        }
    }
    
    public function eventsDelete($towageOrder, $data) {
        $start_do = Carbon::now();
        $towageorderinfo = $this->towageService->findByAttributes(array('OrderNumber'=>$towageOrder,'Sequence'=>$data['Sequence']));
        if(!$towageorderinfo){
            Log::error('TowageService deletion failed,does not exist');
        }

        // Delete Towage Service
        try {
            $this->towageService->getModel()->where(array('OrderNumber'=>$towageOrder,'Sequence'=>$data['Sequence']))->delete();

            $end_do = Carbon::now();        
            $totalDuration = $start_do->diffInSeconds($end_do);
            
            Log::info('Mos Synchronize: Towage Service is successfully deleted with duration (sec): '. $totalDuration );
        } catch (\Exception $e) {   
            Log::error("Mos Synchronize: Towage Service deleting failed - Server Error : ".$e->getMessage());
        }
    }
}
