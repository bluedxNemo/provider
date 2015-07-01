# provider
redis或mysql单例模式
自行实现LOG方法

    include DataProvider.php;
    $config = array(
		'REDIS_CACHE'=>array(
				'type'=>'redis',
				'host'=>'127.0.0.1',
				'port'=>6379,
				'db'=>0,
				'timeout'=>1
		),
		'MYSQL_TEST'=>array(
				'type'=>'mysql',
				'host'=>'127.0.0.1',
				'port'=>'3306',
				'user' => 'user',
				'pwd' => 'pwd',
				'db'=>'database',
				'timeout'=>1
		)
    );
    //进行初始化
    DataProvider::applyConfig($config);
    //获取REDIS对象
    $db = DataProvider::getDataProvider('REDIS_CACHE');
    //获取数据库对象
    $db = DataProvider::getDataProvider('MYSQL_TEST');

    //数据库连接采用PDO方式 无需手动关闭连接
