<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * Redis Cache Helper
 *
 * @modify 2012/12/24
 */
class Rcache {
    /**
     * 连接
     *
     * @var object|null
     */
    protected static $_conn = null;

    /**
     * 本地环境配置
     *
     * @var array
     */
    protected static $_dev_host = array('host'=>'127.0.0.1', 'port'=>'6379');

    /**
     * 生产环境配置
     *
     * @var array
     */
    protected static $_pro_host = array('host'=>'127.0.0.1',   'port'=>'6379');

    /**
     * 初始化(伪单例模式)
     * 此处去除了原有的&init()的引用, 具体请参见PHP &相关用法
     *
     * @return object
     */
    public static function init()
    {
        if(null !== self::$_conn) return self::$_conn;

        $server = ENVIRONMENT == 'development' ? self::$_dev_host : self::$_pro_host;

        self::$_conn = new Redis();

        $conn_status = self::$_conn->connect($server['host'], $server['port']);
        if(!$conn_status) {
            self::$_conn->connect($server['host'], $server['port']);
        }

        return self::$_conn;
    }

    /**
     * 手动关闭连接
     */
    public static function close()
    {
        if(is_object(self::$_conn)) self::$_conn->close();

        self::$_conn = null;
    }
}
