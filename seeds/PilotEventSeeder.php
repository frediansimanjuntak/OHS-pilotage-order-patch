<?php

use Illuminate\Database\Seeder;
use Modules\User\Entities\Agent;
use Modules\Order\Entities\PilotageOrder;
use Modules\Order\Entities\Order;
use Modules\Order\Entities\PilotServiceInfo;
use Modules\Order\Repositories\PilotServiceInfoRepository;

class PilotEventSeeder extends Seeder
{
    protected $pilotserviceinfo;

    public function __construct(PilotServiceInfoRepository $pilotserviceinfo) {
        $this->pilotserviceinfo = $pilotserviceinfo;
    }
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get data from Json
        $json = File::get("database/data/pilot_event.json");
        $datas = json_decode($json, true);
        
        foreach ($datas as $value) {
            $data = $value['pilotServiceInfo'];
            $data = array_filter($data);   
            //Switch according to advice code
            if($data['Advice']=='N'){
                $this->eventsCreate($data);
            }elseif($data['Advice']=='A' || $data['Advice']=='W'){
                $this->eventsUpdate($data['OrderNumber'], $data);
            }elseif($data['Advice']=='D' || $data['Advice']=='Z'){
                $this->eventsDelete($data['OrderNumber'], $data);
            }
        }
    }

    public function eventsCreate($data) {
        $event = $this->pilotserviceinfo->getModel()->where(array('OrderNumber'=>$data['OrderNumber'],'Source'=>$data['Source']))->first();
        if ($event) {
            //go to update if already exist
            $this->eventsUpdate($data['OrderNumber'], $data);
        } else {
            // Create pilot service info
            $serviceCreate =  $this->pilotserviceinfo->getModel()->firstOrCreate($data);
            if(!$serviceCreate){
                Log::error('Mos Synchronize: Pilotservice creation failed - Server Error');
            }else{
                Log::info('Mos Synchronize: Pilot service info created');
            }
        }        
    }
    
    public function eventsUpdate($pilotorder, $data){
        $pilotservice = $this->pilotserviceinfo->getModel()->where(array('OrderNumber'=>$pilotorder,'Source'=>$data['Source'],'SequenceNumber'=>$data['SequenceNumber']))->first();
        if(!$pilotservice){
            Log::error('Mos Synchronize: PilotService info not found');
            $this->eventsCreate($data);
        } else {
            $service = $this->pilotserviceinfo->getModel()->where(array('OrderNumber'=>$pilotorder,'Source'=>$data['Source'],'SequenceNumber'=>$data['SequenceNumber']))->update($data);
            if(!$service){
                Log::error('Mos Synchronize: Pilotservice updation failed');
            }else{
                Log::info('Mos Synchronize: Pilot service info updated');
            }            
        }
    }
    
    public function eventsDelete($pilotorder, $data) {
        $pilotorderinfo = $this->pilotserviceinfo->findByAttributes(array('OrderNumber'=>$pilotorder,'Source'=>$data['Source'],'SequenceNumber'=>$data['SequenceNumber']));
        if(!$pilotorderinfo){
            Log::error('PilotServiceInfo deletion failed,does not exist');
        }
        $res = $this->pilotserviceinfo->getModel()->where(array('Source'=>$data['Source'],'OrderNumber'=>$pilotorder,'SequenceNumber'=>$data['SequenceNumber']))->delete();
        if(!$res){
            Log::error('Mos sync:  Pilotservice info  not deleted successfully');
        } else {
            Log::info('Mos sync: Pilotservice info deleted');
        }
    }
}
