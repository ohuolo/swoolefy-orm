<?php
/**
 * 连接池封装.
 * User: user
 * Date: 2018/9/1
 * Time: 13:36
 */
namespace Think;
use Swoole\Coroutine\Channel;
use PDO;

class Pool {
    private $min;//最少连接数
    private $max;//最大连接数
    private $count;//当前连接数
    private $key;//当前连接数
    private $connections;//连接池组
    protected $spareTime;//用于空闲连接回收判断
    protected $config;//用于空闲连接回收判断
    protected $params;//用于空闲连接回收判断
    //数据库配置
    protected $dbConfig = array(
        'host' => '10.0.2.2',
        'port' => 3306,
        'user' => 'root',
        'password' => 'root',
        'database' => 'test',
        'charset' => 'utf8',
        'timeout' => 2,
    );

    private $inited = false;
    public static $instance = [];

    protected function createDb($config,$params){
        $db = new PDO($config['dsn'], $config['username'], $config['password'], $params);
        return $db;
    }

    public function __construct($conf,$key) {
        $this->key = $key;
        $this->conf = $conf;
        $config = $conf['config'];
        $this->min = isset($config['min'])&&$config['min']>0?$config['min']:5;
        $this->max = isset($config['max'])&&$config['max']>0?$config['max']:20;
        $this->spareTime = isset($config['spareTime'])&&$config['spareTime']>0?$config['spareTime']:10 * 3600;
        $this->connections = new Channel($this->max + 1);
    }
    public static function getInstance($conf) {
        $key = md5(json_encode($conf));

        if (empty(self::$instance[$key])) {
            $random = rand(0,100000);
            self::$instance[$key] = new Pool($conf,$key);
            self::$instance[$key]->gcSpareObject();
        }
        return self::$instance[$key];
    }
    protected function createObject() {
        $obj = null;

        $config = $this->conf['config'] ;
        $params = $this->conf['params'] ;
        $db = $this->createDb($config,$params);
        if ($db) {
            $obj = [
                'last_used_time' => time(),
                'db' => $db,
            ];
        }
        return $obj;
    }

    /**
     * 初始换最小数量连接池
     * @return $this|null
     */
    public function init() {
        if ($this->inited) {
            return null;
        }
        return $this;
    }

    public function getConnection($timeOut = 3) {
        $obj = null;
        if ($this->connections->isEmpty()) {
            if ($this->count < $this->max) {//连接数没达到最大，新建连接入池
                $this->count++;
                $obj = $this->createObject();
            } else {
                $obj = $this->connections->pop($timeOut);//timeout为出队的最大的等待时间
            }
        } else {
            $obj = $this->connections->pop($timeOut);
        }
        if($obj){
            $obj['conf'] = $this->conf;
        }
        return $obj;
    }

    public function free($obj) {
        if ($obj) {
            $this->connections->push($obj);
        }
    }
    public function gone($obj){
        if ($obj) {
            $this->count--;
            unset($obj['db']);
        }
    }

    /**
     * 处理空闲连接
     */
    public function gcSpareObject()
    {
        swoole_timer_tick(120000, function () {
            $list = [];
            /*echo "开始检测回收空闲链接" . $this->connections->length() . PHP_EOL;*/
            if ($this->connections->length() < intval($this->max * 0.5)) {
                //echo "请求连接数还比较多，暂不回收空闲连接\n";
            }#1
            while (true) {
                if (!$this->connections->isEmpty()) {
                    $obj = $this->connections->pop(0.001);
                    $last_used_time = $obj['last_used_time'];
                    if ($this->count > $this->min && (time() - $last_used_time > $this->spareTime)) {//回收
                        $this->count--;
                    } else {
                        array_push($list, $obj);
                    }
                } else {
                    break;
                }
            }
            foreach ($list as $item) {
                $this->connections->push($item);
            }
            unset($list);
        });

    }





}
