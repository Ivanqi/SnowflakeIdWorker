<?php
namespace SnowflakeIdWorker;

/**
 * 
 * SnowFlake的结构如下(每部分用-分开):<br>
 * 0 - 0000000000 0000000000 0000000000 0000000000 0 - 00000 - 00000 - 000000000000 <br>
 * 1位标识，最高位是符号位，正数是0，负数是1，所以id一般是正数，最高位是0
 * 41位时间截(毫秒级)，注意，41位时间截不是存储当前时间的时间截，而是存储时间截的差值（当前时间截 - 开始时间截)
 *   这里的的开始时间截，一般是我们的id生成器开始使用的时间，由我们程序来指定的
 *   41位的时间截，可以使用69年，年T = (1L << 41) / (1000L * 60 * 60 * 24 * 365) = 69
 * 10位的数据机器位，可以部署在1024个节点，包括5位datacenterId和5位workerId<br>
 * 12位序列，毫秒内的计数，12位的计数顺序号支持每个节点每毫秒(同一机器，同一时间截)产生4096个ID序号<br>
 *  加起来刚好64位，为一个Long型。<br>
 * SnowFlake的优点是，整体上按照时间自增排序，并且整个分布式系统内不会产生ID碰撞(由数据中心ID和机器ID作区分)，并且效率较高，经测试，SnowFlake每秒能够产生26万ID左右
 */
class IdWorker  
{
    // 机器id所占的位数
    const workerIdBits = 5;             

    // 序列在id中占的位置
    const sequenceBits = 12;     
    
    // 数据标识id所占的位数
    const datacenterIdBits = 5;
    
    // 时间标识id所占的位数
    const timestampIdBits = 41;

    // 工作机器ID(0 - 31)
    static $workerId;

    // 数据中心ID(0 - 31)
    static $datacenterId;

    // 开始时间截 2014-05-13
    static $twepoch = 1399943202863;
    
    // 毫秒内序列
    static $sequence = 0;               
    
    // 支持最大机器数id，结果是31(这个移位算法可以很快的计算出几位二进制树所能表示的最大十进制)
    static $maxWorkerId = -1 ^ (-1 << self::workerIdBits);
    
    // 支持的最大数据标识id, 结果是31
    static $maxDatacenterId = -1 ^ (-1 << self::datacenterIdBits);

    // 机器ID向左移12位
    static $workerIdShift = self::sequenceBits;

    // 数据标识id向左移17位(12 + 5)
    static $datacenterIdShift = self::sequenceBits + self::workerIdBits;

    // 时间戳向左移22位(12 + 5 + 5)
    static $timestampLeftShift = self::sequenceBits + self::workerIdBits + self::datacenterIdBits;

    // 生成序列掩码，这里是4095
    static $sequenceMask = -1 ^ (-1 << self::sequenceBits);

    // 时间序列掩码,这里是 2199023255550
    static $timestampMask = -1 ^ (-1 << self::timestampIdBits);

    // 上次生成ID的时间戳
    private static $lastTimestamp = -1;
    

    private static $self = NULL;


    public function getInstance()
    {
        if (self::$self == NULL) {
            self::$self = new self();
        }

        return self::$self;
    }

    public function __construct($workId = 0, $datacenterId = 0)
    {       
        if ($workId > self::$maxWorkerId || $workId < 0) {
            throw new \Exception('worker Id can\'t be greater than ' . self::$maxWorkerId. 'or less than 0');
        }

        if ($datacenterId > self::$maxDatacenterId || $datacenterId < 0) {
            throw new \Exception('datacenter Id can\'t be greater than ' . self::$maxDatacenterId. 'or less than 0');
        }

        self::$workerId = $workId;
        self::$datacenterId = $datacenterId;
    }

    /**
     * 返回以毫秒为单位的当前时间
     * @return 当前时间(毫秒)
     */
    private function timeGen()
    {
        // 获取当前时间戳
        $time = explode(' ', microtime());
        $time2 = substr($time[0], 2, 3);
        return $time[1] . $time2;
    }

    /**
     * 阻塞到下一个毫秒，知道获得新的时间戳
     * @param $lastTimestamp 上次生成ID的时间戳
     * @return 当前时间戳
     */
    private function tilNextMillis($lastTimestamp)
    {
        $timestamp = $this->timeGen();
        while ($timestamp <= $lastTimestamp) {
            $timestamp = $this->timeGen();
        }

        return $timestamp;
    }

    /**
     * 获取下一个ID(非线程安全)
     * @return id
     */
    public function nextId()
    {
        $timestamp = $this->timeGen();
        // 如果是同一时间生成的，则进行毫秒内序列
        if (self::$lastTimestamp == $timestamp) {
            self::$sequence = (self::$sequence + 1) & self::$sequenceMask;
            // 毫秒内序列溢出
            if (self::$sequence == 0) {
                // 阻塞到下一个毫秒，获得新的时间戳
                $timestamp = $this->tilNextMillis(self::$lastTimestamp);
            }
        } else {
            self::$sequence = 0;
        }

        // 如果当前时间小于上一次ID生成的时间戳，说明系统时钟回退过。所以要抛出异常
        if ($timestamp < self::$lastTimestamp) {
            throw new \Excwption("Clock move backwards. Refusing to generate id for " . (self::$lastTimestamp - $timestamp) . "millseconds");
        }

        // 上次生成ID的时间戳
        self::$lastTimestamp = $timestamp;

        // 移位并通过运算拼到一起组成64位的ID
        $time = (sprintf('%.f', $timestamp) - sprintf('%.f', self::$twepoch));
        $nexId = ( ($time & self::$timestampMask) << self::$timestampLeftShift)
            | (self::$datacenterId << self::$datacenterIdShift)
            | (self::$workerId << self::$workerIdShift)
            | (self::$sequence);

        return $nexId;
    }
}
