<?php
/**
 * 获取数据源
 * @author bluedxNemo
 */
class DataProvider{
	/**
	 * 连接配置表
	 * @var array
	 */
	private static $providers;
	/**
	 * 单例
	 * @var DataProvider
	 */
	private static $instance;
	/**
	 * 连接池
	 * @var array
	 */
	private $pool;

	/**
	 * 初始化连接池
	 */
	private function __construct(){
		$this->pool = array();
	}
	/**
	 * 生成单例并注册销毁函数
	 */
	private static function init(){
		if(!self::$instance){
			self::$instance = new DataProvider();
			register_shutdown_function(array(
				self::$instance,
				'shutdown'
			));
		}
	}
	/**
	 * 获取单例
	 * @return DataProvider
	 */
	public static function getInstance(){
		self::init();
		return self::$instance;
	}
	/**
	 * 销毁函数
	 */
	public function shutdown(){
		foreach($this->pool as $provider){
			$provider->close();
		}
	}
	/**
	 * 应用连接配置
	 * @param array $config
	 */
	public static function applyConfig($config){
		self::$providers = $config;
	}
	/**
	 * 获取数据源
	 * @param string $key
	 * @return iAccessWrapper
	 */
	public static function getDataProvider($key){
		self::init();
		if(!isset(self::$providers[$key])){
			return false;
		}
		$config = self::$providers[$key];
		if(!isset(self::$instance->pool[$key])){
			self::$instance->pool[$key] = new AccessWrapper($key, $config);
		}
		return self::$instance->pool[$key];
	}
	/**
	 * 直接调用数据操作方法
	 * @param string $name
	 * @param array $arguments
	 * @return mixed
	 */
	public function __call($name, $arguments){
		$key = array_shift($arguments);
		$provider = self::getDataProvider($key);
		if($provider){
			return call_user_func_array(array(
				$provider,
				$name
			), $arguments);
		}
	}
}
/**
 * 数据源接口
 * @author bluedxNemo
 */
interface iAccessWrapper{
	/**
	 * 初始化函数
	 * @param string $name 连接名
	 * @param array $config 连接配置
	 * @throws Exception
	 */
	public function __construct($name, $config);
	/**
	 * 关闭连接
	*/
	public function close();
}
/**
 * Redis数据源实现
 * @author bluedxNemo
 */
class AccessWrapper implements iAccessWrapper{
	/**
	 * 连接池
	 * @var array
	 */
	private static $connections = array();
	/**
	 * redis连接
	 * @var Redis
	*/
	private $connection;
	/**
	 * 连接名
	 * @var string
	 */
	private $name;
	/**
	 * 数据库
	 * @var int
	 */
	private $db;
	/**
	 * 数据库
	 * @var boolen
	 */
	const IS_DEBUG = TRUE;
	/**
	 * 初始化函数
	 * @param string $name 连接名
	 * @param array $config 连接配置
	 * @throws Exception
	 */
	public function __construct($name, $config){
		
		$host = $config['host'];
		$port = $config['port'];
		$db = isset($config['db']) ? $config['db'] : 0;
		$conn = &self::findServer($config);
		$this->db = $db;
		$this->name = $name;
		if(!$conn){
			switch(strtolower($config['type'])){
				case 'mysql':
					$user = $config['user'];
					$pwd = $config['pwd'];
					$timeout = 2;//秒
					if(!empty($config['timeout'])){
						$timeout = +$config['timeout'];
					}
					try{
						$conn = array(
								'type' => $config['type'],
								'host'=>$host,
								'port'=>$port,
								'db'=>$db,
								'name'=>$name,
								'ctime'=>microtime(true),
								'handle' => new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8","{$user}","{$pwd}", array(
											//PDO::ATTR_TIMEOUT => $timeout,
											PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
											PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
									))
						);
						//php5.3.6以下版本会忽略DSN中的charset设定 这里手动指定字符集
						$conn['handle']->query('set names utf8;');
						//禁用prepared statements的仿真效果  防止SQL注入
						$conn['handle']->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
						Log("MysqlConnected: {$this->name} $host:$port $db");
						self::$connections[] = &$conn;
					} catch(PDOException $e){
						$message = $e->getMessage();
						Log("MysqlError: $message at {$this->name} connect");
					}
					break;
				default://默认redis
					$conn = array(
							'type' => $config['type'],
							'host'=>$host,
							'port'=>$port,
							'db'=>0,
							'name'=>$name,
							'handle'=>new Redis()
					);
					$timeout = 0;
					if(!empty($config['timeout'])){
						$timeout = +$config['timeout'];
					}
					try{
						$start_time = microtime(true);
						if(!$conn['handle']->connect($host, $port, $timeout)){
							$took = microtime(true) - $start_time;
							throw new Exception("Connection failed, took {$took}s.");
						}
						$conn['ctime'] = microtime(true);
						Log("RedisConnected: {$this->name} $host:$port");
						self::$connections[] = &$conn;
					} catch(Exception $e){
						$message = $e->getMessage();
						Log("RedisError: $message at {$this->name} connect");
					}			
					break;
			}

		}
		$this->connection = &$conn;
	}

	/**
	 * 查找既存连接 兼容MYSQL
	 * @param string $host
	 * @param int $port
	 * @return array
	 */
	private static function &findServer($config, $ret = false){
		foreach(self::$connections as &$conn){
			if($conn['type'] == 'mysql'){
				if($conn['host'] === $config['host'] && $conn['port'] === $config['port'] && $conn['db'] === $config['db']){
					return $conn;
				}
			}else{
				//redis
				if($conn['host'] === $config['host'] && $conn['port'] === $config['port']){
					return $conn;
				}				
			}
		}
		return $ret;
	}
	/**
	 * 关闭连接
	 * @see iAccessWrapper::close()
	 */
	public function close(){
		if($this->connection['type'] == 'mysql'){
			$this->connection['handle'] = null;
			$time = number_format(microtime(true) - $this->connection['ctime'], 8);
			Bingo_Log::notice("MysqlClosed: $time {$this->name}", self::LOG_MODULE);
		}else{
			if($this->connection['name'] == $this->name){
				try{
					$this->connection['handle']->close();
					$time = number_format(microtime(true) - $this->connection['ctime'], 8);
					Bingo_Log::notice("RedisClosed: $time {$this->name}", self::LOG_MODULE);
				} catch(Exception $e){
					$message = $e->getMessage();
					Bingo_Log::warning("RedisError: $message at {$this->name} close", self::LOG_MODULE);
				}
			}
		}
	}
	/**
	 * 确保当前访问的是配置文件中的数据库
	 * 只适用于redis
	 * @throws Exception
	 */
	private function ensureDB(){
		if($this->connection['db'] != $this->db){
			try{
				if(!$this->connection['handle']->select($this->db)){
					throw new Exception('Select Failed');
				}
				$this->connection['db'] = $this->db;
				Bingo_Log::notice("RedisSelect: {$this->name} {$this->db}", self::LOG_MODULE);
			} catch(Exception $e){
				$message = $e->getMessage();
				Bingo_Log::warning("RedisError: $message at {$this->name} select", self::LOG_MODULE);
			}
		}
	}
	/**
	 * 判断是否调试模式
	 * @return bool
	 */
	private static function isDebugMode(){
		return self::IS_DEBUG;
	}
	/**
	 * 调用Redis库函数
	 * @param string $name
	 * @param array $arguments
	 * @throws Exception
	 * @return mixed
	 */
	public function __call($name, $arguments){
		try{
			if(method_exists($this->connection['handle'], $name)){
				if($this->connection['type'] == 'redis') $this->ensureDB();
				$time_start = microtime(true);
				$ret = call_user_func_array(array(
					$this->connection['handle'],
					$name
				), $arguments);
				if(self::isDebugMode()){
					$time = number_format(microtime(true) - $time_start, 8);
					$args = array();
					foreach($arguments as $arg){
						$arg = (string)$arg;
						if(strlen($arg) > 50){
							$arg = substr($arg, 0, 50) . '...';
						}
						$args[] = $arg;
					}
					$args = implode(', ', $args);
					LOG("handleCall: $time {$this->name} $name($args)");
				}
				return $ret;
			} else{
				throw new Exception('undefined method');
			}
		} catch(Exception $e){
			$message = $e->getMessage();
			LOG("handleError: $message at {$this->name} $name");
		}
	}
	
	public function pdo_query($sql, $param){
		$stmt = null;
		$s = $sql;
		if($param){
			foreach($param as $k => $v){
				$s = str_replace($k, $v, $s);
			}
		}
		try{
			$time_start = microtime(true);
			$stmt = $this->connection['handle']->prepare($sql);
			$stmt->execute($param);
			if(self::isDebugMode()){
				$time = number_format(microtime(true) - $time_start, 8);
				LOG("sql_exec:$time {$this->name} [$s]");
			}
		}catch(Exception $e){
			$message = $e->getMessage();
			LOG("mysqlError: $message at {$this->name} [$s]");
		}
		return $stmt;
	}
}

?>
