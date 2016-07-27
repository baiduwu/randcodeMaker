<?php
namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Input;
use Illuminate\Database\Eloquent\Model;
use App\Services\ErrMapping;
use App\Services\RedisKey;
use App\Jobs\CoderMakerJob;
use Illuminate\Support\Facades\Queue;

class CodeMaker extends Model
{
    public $timestamps = false;
    protected $table = 'code_config';
    private static $_instance;
    private $_caller;
    private $_codeMaxValue;
    //当前最大券码装载区间  装载状态
    const ALLOCATION_INTERVAL_STATUS_ING = 1;//装载中
    const ALLOCATION_INTERVAL_STATUS_DONE = 2;//装载完成
    const ALLOCATION_INTERVAL_STATUS_FAIL = 3;//装载完成

    public function __construct() {
        $this->_caller = config('code.caller');
        $this->_codeMaxValue = config('code.code_max_value');
        //var_dump($this->_codeMaxValue);exit;
    }

    public static function getInstance() {
        if (empty(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function applyService($callerId, $callerUniqueId, $codeMaxValue, $intervalNum, $threshold) {
        //echo 'hello';exit;
        //var_dump($this->_caller);
        $callerIds = array_values($this->_caller);
        if (!in_array($callerId, $callerIds)) {
            throw new \Exception('非法调用callId', ErrMapping::ERR_MARKET_CODE_ILLEGAL_CALLER_ID);
        }
        //todo 校验参数的合法性
        if ($codeMaxValue <= 0 || $codeMaxValue > $this->_codeMaxValue) {
            throw new \Exception('code_max_value非法', ErrMapping::ERR_BAD_PARAMETERS);
        }
        if ($intervalNum <= 0 || $intervalNum >= $codeMaxValue) {
            throw new \Exception('interval_num非法', ErrMapping::ERR_BAD_PARAMETERS);
        }
        if ($threshold <= 0 || $intervalNum > ($codeMaxValue / $intervalNum)) {
            throw new \Exception('interval_num非法', ErrMapping::ERR_BAD_PARAMETERS);
        }

        //可重入判断

        $cond = ['caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId];
        $data = self::select('*')->where($cond)->first();
        if($data){
            Log::warning(sprintf("class[%s] func[%s] callId[%s] callerUniqueId[%s]  msg[已申请过当前活动的提取码服务！]", __CLASS__, __FUNCTION__, $callerId, $callerUniqueId));
            return 1;
        }

        //入库
        $codeConfig = array('caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId, 'code_max_value' => $codeMaxValue, 'interval_num' => $intervalNum, //'current_interval_' => 0,
            'threshold' => $threshold,);
        try {
            DB::beginTransaction();
            self::addCodeConfig($codeConfig);
            //进队列 需要计算$codeStart, $codeEnd
            $codeStart = 1;
            $codeEnd = $codeMaxValue / $intervalNum;
            $this->pushCoderMaker($callerId, $callerUniqueId, 1, $codeStart, $codeEnd, 0);// 因为这里是第一次装码， 所以$interval_id =1  allocation_inteval_id = 0
            DB::commit();
        }
        catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
        return 1;
    }

    public function generateCode($callerId, $callerUniqueId) {
        //校验活动是否发布，以及在券码数据表数据。
        $codeConfig = self::codeConfigDetail($callerId, $callerUniqueId);
        if(empty($codeConfig)){
            throw new \Exception('活动未发布或未申请提取码服务！',ErrMapping::ERR_MARKET_NEVER_APPLY_CODE_SERVICE);
        }
        $interval_num = $codeConfig['interval_num'];
        $code_max_value = $codeConfig['code_max_value'];
        $allocation_interval_id = $codeConfig['allocation_interval_id'];
        $allocation_interval_status = $codeConfig['allocation_interval_status'];
        $current_interval_id = $codeConfig['current_interval_id'];
        $radio = $code_max_value / $interval_num;
        $remainder = $code_max_value % $interval_num;
        Log::info('radio:' . $radio);
        Log::info('remainder:' . $remainder);
        $max_interval = $remainder == 0 ? $radio : ($radio + 1);//计算最大区间id
        //目前的设计中 $allocation_interval_id 最多只会比 $current_interval_id 大1
        if (($allocation_interval_id < $current_interval_id) || (($allocation_interval_id == $current_interval_id) && $allocation_interval_status != self::ALLOCATION_INTERVAL_STATUS_DONE)) {
            Log::warning(sprintf("class[%s] func[%s] callId[%s] callerUniqueId[%s]  msg[当前区间%s提取码暂未装载！,请稍侯，或手动触发！]", __CLASS__, __FUNCTION__, $callerId, $callerUniqueId, $current_interval_id));
            throw new \Exception('暂无可用提取码！', ErrMapping::ERR_MARKET_CODE_NOT_ENOUGH);
        }

        //todo 当前区间券码装载  状态校验
        $codeRedisKey = RedisKey::getKey(RedisKey::CODE_MAKER, ['caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId, 'interval_id' => $current_interval_id]);
        //echo $codeRedisKey;
        //done 校验是否需要生成下一个区间的提取码
        $num = app('redis')->scard($codeRedisKey);
        //检查要装码的区间id 的状态  当$allocation_interval_id > $current_interval_id时，说明之前生成券码时，已经开始生成下一个区间的提取码了
        /**
         * 1 、阈值
         * 2 、当前使用区间id 与 已分配区间id 相同
         * 3 、当前区间id不能是最大区间id
         */
        if ($num <= $codeConfig['threshold'] && $allocation_interval_id == $current_interval_id && $max_interval != $current_interval_id) {
            $codeStart = $interval_num * $current_interval_id + 1;
            if (($max_interval - 1) == $allocation_interval_id) {
                $codeEnd = $code_max_value;//要装载区间是最后一个区间的时候
            }
            else {
                $codeEnd = $interval_num * ($current_interval_id + 1);
            }
            //todo 最后一个区间的判断
            try {
                DB::beginTransaction();
                $this->pushCoderMaker($callerId, $callerUniqueId, $current_interval_id + 1, $codeStart, $codeEnd, $allocation_interval_id);
                DB::commit();
            }
            catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
        $code = app('redis')->spop($codeRedisKey);
        // null时候，说明当前区间提取码用完
        if (NULL === $code) {
            if ($max_interval != $current_interval_id) {
                //此时如果再没有，那就是队列挂了，没有生产最新的码
                $codeNextRedisKey = RedisKey::getKey(RedisKey::CODE_MAKER, ['caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId, 'interval_id' => $current_interval_id + 1]);
                $code = app('redis')->spop($codeNextRedisKey);
                if (empty($code)) {
                    Log::warning(sprintf("class[%s] func[%s] callId[%s] callerUniqueId[%s]  msg[当前区间下一个区间id:%s提取码暂未装载！]", __CLASS__, __FUNCTION__, $callerId, $callerUniqueId, $current_interval_id + 1));

                }
                //当前区间的提取码已耗尽，将current_interval_id字段加1  这里需要开始for update 事务，防止高并发将current_interval_id 多次加1
                self::incCurrentIntId($callerId, $callerUniqueId, $current_interval_id);
            }
            else {
                //最后一个区间的提起码也消耗完了
                throw new \Exception('提取码被用光啦，请联系系统管理员', ErrMapping::ERR_MARKET_CODE_NOT_ENOUGH);
            }
        }
        //var_dump($code);
        //  前缀补0
        if ($code) {
            Log::info(sprintf("class[%s] func[%s] callId[%s] callerUniqueId[%s]  msg[本次生成券码：%s]", __CLASS__, __FUNCTION__, $callerId, $callerUniqueId, sprintf('%06d', $code)));
            $formatStr = '%0'.strlen($code_max_value).'d'; //计算生成几位长度的 提取码
            return sprintf($formatStr, $code);//用一个6位数的数字格式化后边的参数，如果不足6位就补零
        }
        else {
            throw new \Exception('暂无可用提取码！', ErrMapping::ERR_MARKET_CODE_NOT_ENOUGH);
        }
    }

    /**
     * 获取用户操作记录
     *
     * @param
     *  $data
     * @return $result
     */
    public function logList($cond, $limit = 10) {
        $result = self::select('*')->where($cond)->orderBy('id', 'desc')->paginate($limit)->toArray();
        return uc_paginate_ret($result);
    }

    public function codeConfigDetail($callerId, $callerUniqueId, $transaction = false) {
        $cond = ['caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId];
        if ($transaction) {
            $detail = self::select('*')->where($cond)->lockForUpdate()->first();
        }
        else {
            $detail = self::select('*')->where($cond)->first();
        }
        if ($detail) {
            $detail = $detail->toArray();
        }
        return $detail;
    }

    /**
     * 添加用户行为记录
     *
     * @param
     *            $data
     * @return $result
     */
    public function addCodeConfig($data) {
        if (empty($data['create_at'])) {
            $data['create_at'] = time();
        }
        $result = self::insertGetId($data);
        return $result;
    }

    public function updateInfo($conds, $data) {
        $data['update_at'] = time();
        //$data['update_by'] = RyHelper::getUsername();
        return self::where($conds)->update($data);
    }

    /**
     * DESC allocation_interval_id 加1 并且状态为ing
     */
    public function incAllocationIntId($callerId, $callerUniqueId) {
        //$affected = DB::update('update '.$this->table.' set allocation_interval_id=allocation_interval_id+1 where caller_id = ? and caller_unique_id = ?', [$callerId, $callerUniqueId]);
        $cond = ['caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId];
        //var_dump($cond);
        self::where($cond)->increment('allocation_interval_id', 1, ['allocation_interval_status' => self::ALLOCATION_INTERVAL_STATUS_ING, 'update_at' => time()]);
    }

    /**
     * desc current_interval_id 加1
     * @param $callerId
     * @param $callerUniqueId
     */
    public function incCurrentIntId($callerId, $callerUniqueId, $current_interval_id) {
        //$affected = DB::update('update '.$this->table.' set allocation_interval_id=allocation_interval_id+1 where caller_id = ? and caller_unique_id = ?', [$callerId, $callerUniqueId]);
        $cond = ['caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId];
        $detail = self::codeConfigDetail($callerId, $callerUniqueId, true);
        if (!empty($detail) && $current_interval_id == $detail['current_interval_id']) {
            self::where($cond)->increment('current_interval_id', 1, ['update_at' => time()]);
        }
        //self::commit();
        //var_dump($cond);

    }

    private function pushCoderMaker($callerId, $callerUniqueId, $intervalId, $codeStart, $codeEnd, $allocationIntervalId) {
        Log::info('new internal');
        $detail = self::codeConfigDetail($callerId, $callerUniqueId, true);
        if (!empty($detail) && $allocationIntervalId < $detail['allocation_interval_id']) {
            Log::info(sprintf("class[%s] func[%s] callId[%s] callerUniqueId[%s]  msg[遇到并发了！已装载当前区间:%s提取码]", __CLASS__, __FUNCTION__, $callerId, $callerUniqueId, $intervalId));
            return;
        }
        try {
            //$cond = ['caller_id' => $callerId, 'caller_unique_id' => $callerUniqueId];
            $job = new CoderMakerJob($callerId, $callerUniqueId, $intervalId, $codeStart, $codeEnd);
            $jobId = Queue::connection('code_maker')->push($job);
            Log::info('jobId:' . $jobId);
            if ($jobId) {
                //更新当前 区间id 的装载状态为 ing
                CodeMaker::getInstance()->incAllocationIntId($callerId, $callerUniqueId);
            }
            else {
                //todo 是否需要抛异常？
                Log::warning(sprintf("class[%s] func[%s] callId[%s] callerUniqueId[%s]  msg[区间id:%s装码推送队列任务失败!]", __CLASS__, __FUNCTION__, $callerId, $callerUniqueId, $intervalId));
            }
        }
        catch (\Exception $e) {
            throw $e;
        }


    }
}