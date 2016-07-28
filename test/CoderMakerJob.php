<?php

namespace test;

use App\Models\CodeMaker;
use Illuminate\Support\Facades\Log;
use App\Services\HttpApi;
use Illuminate\Support\Facades\Redis;
use App\Services\RedisKey;

class CoderMakerJob extends Job {

	private $caller_id;
	private $caller_unique_id;
    private $interval_id; //本次要装载的区间id
	private $code_start;
    private $code_end;
	public function __construct($caller_id, $caller_unique_id, $interval_id, $code_start, $code_end) {
		$this->caller_id = $caller_id;
		$this->caller_unique_id = $caller_unique_id;
        $this->interval_id = $interval_id;
		$this->code_start = $code_start;
		$this->code_end = $code_end;
	}
	
	/**
	 * Execute the job.
	 *
	 * @return void
	 */
	public function handle() {
        //需要捕获异常吗
		//redis操作
        $codeRedisKey = RedisKey::getKey(RedisKey::CODE_MAKER, ['caller_id' => $this->caller_id , 'caller_unique_id' => $this->caller_unique_id, 'interval_id' => $this->interval_id ]);
        $data = array();
        for($i = $this->code_start ; $i<=$this->code_end; $i++){
            $data[] = $i;
        }
        //echo $codeRedisKey;
        $addNum =app('redis')->sadd($codeRedisKey, $data);
        $cond = ['caller_id' => $this->caller_id, 'caller_unique_id' => $this->caller_unique_id];
        if(false == $addNum){
            Log::warning(sprintf('callId[%s] callerUniqueId[%s] allocationIntervalId[%s] msg[生成code出错!]', $this->caller_id, $this->caller_unique_id, $this->interval_id));
            $allocation_interval_status = CodeMaker::ALLOCATION_INTERVAL_STATUS_FAIL;
        }
        else if( 0 == $addNum ){
            //更新code_config表的 allocation_interval_id+1
            Log::warning(sprintf('callId[%s] callerUniqueId[%s] allocationIntervalId[%s] msg[无code生成!]', $this->caller_id, $this->caller_unique_id, $this->interval_id));
            $allocation_interval_status = CodeMaker::ALLOCATION_INTERVAL_STATUS_DONE;
        }else{
            Log::info(sprintf('callId[%s] callerUniqueId[%s] allocationIntervalId[%s] msg[生成当前队列提取码成功！]', $this->caller_id, $this->caller_unique_id, $this->interval_id));
            $allocation_interval_status = CodeMaker::ALLOCATION_INTERVAL_STATUS_DONE;
        }
        $data = array(
            'allocation_interval_status' => $allocation_interval_status,
        );
        CodeMaker::getInstance()->updateInfo($cond, $data);
        Log::info(sprintf('callId[%s] callerUniqueId[%s] allocationIntervalId[%s] job done!', $this->caller_id, $this->caller_unique_id, $this->interval_id));
	}
}