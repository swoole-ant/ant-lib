<?php

namespace dao;

use common\ERROR;
use common\Log;
use exceptionHandler\DaoException;
use ZPHP\Core\Config as ZConfig,
    ZPHP\Db\Pdo as ZPdo;

abstract class Base
{
    private $entity;

    private static $_dbs = [];
    /**
     * @var ZPdo
     */
    private $_db = null;
    private $_dbTag = null;
    protected $dbName;
    protected $tableName;
    protected $className;


    /**
     * Base constructor.
     * @param null $entity
     * @param string $useDb //使用的库名,不使用数据库,可设置为空
     * @throws \Exception
     */
    public function __construct($entity = null, $useDb = 'common')
    {
        $this->entity = $entity;
        $this->_dbTag = $useDb;
        if ($entity && $useDb) {
            $this->init();
        }
    }

    /**
     * @return null|ZPdo
     * @throws \Exception
     * @desc 使用db
     */
    public function init()
    {
        if(!$this->_dbTag) {
            return null;
        }
        if (empty(self::$_dbs[$this->_dbTag])) {
            $config = ZConfig::getField('pdo', $this->_dbTag);
            self::$_dbs[$this->_dbTag] = new ZPdo($config, $this->entity, $config['dbname']);
        }
        $this->_db = self::$_dbs[$this->_dbTag];
        $this->_db->setClassName($this->entity);
        $this->_db->checkPing();
        return $this->_db;
    }

    /**
     * @param $tableName
     * @desc 更换表
     */
    public function changeTable($tableName)
    {
        $this->_db->setTableName($tableName);
    }

    /**
     * @return bool
     * @desc 关闭db
     */
    public function closeDb($tag = null)
    {
        if (empty($tag)) {
            $tag = $this->_dbTag;
        }
        if (empty($tag)) {
            return true;
        }
        if (empty($this->_db)) {
            return true;
        }
        $this->_db->close();
        unset(self::$_dbs[$tag]);
        return true;
    }

    /**
     * @param $id
     * @return mixed
     * @throws \Exception
     * @desc 跟据 id 获取记录
     */
    public function fetchById($id)
    {
        try {
            return $this->doResult($this->_db->fetchEntity("id={$id}"));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param $where
     * @param null $params
     * @param string $fields
     * @param null $orderBy
     * @return mixed
     * @throws \Exception
     */
    public function fetchEntity($where, $params = null, $fields = '*', $orderBy = null)
    {
        try {
            return $this->doResult($this->_db->fetchEntity($this->parseWhere($where), $params, $fields, $orderBy));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }


    /**
     * @param array $items
     * @param null $params
     * @param string $fields
     * @param null $orderBy
     * @param null $limit
     * @return mixed
     * @throws \Exception
     * @desc 多行记录获取
     */
    public function fetchAll(array $items = [], $params = null, $fields = '*', $orderBy = null, $limit = null)
    {
        try {
            return $this->doResult($this->_db->fetchAll($this->parseWhere($items), $params, $fields, $orderBy, $limit));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param $items
     * @return string
     * @desc 解析where
     */
    private function parseWhere($items)
    {

        if (empty($items)) {
            return 1;
        }

        if (is_string($items)) {
            return $items;
        }

        $where = '1';

        if (!empty($items['union'])) {
            foreach ($items['union'] as $union) {
                $where .= " {$union}";
            }
            unset($items['union']);
        }

        foreach ($items as $k => $v) {
            $where .= " AND {$k} {$v}";
        }

        return $where;
    }

    /**
     * @param string $where
     * @return mixed
     * @throws \Exception
     */
    public function fetchWhere($where = '')
    {
        try {
            return $this->doResult($this->_db->fetchAll($this->parseWhere($where)));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param $attr
     * @param array $items
     * @param int $change
     * @return mixed
     * @throws \Exception
     */
    public function update($attr, $items = [], $change = 0)
    {
        if (empty($attr)) {
            $attr = new $this->entity;
            $attr->create();
        }

        $fields = array();
        $params = array();
        if (is_object($attr)) {
            foreach ($attr->getFields() as $key) {
                $fields[] = $key;
                $params[$key] = $attr->$key;
            }
        } else {
            $fields = array_keys($attr);
            $params = $attr;
        }

        if (!empty($items)) {
            $where = $this->parseWhere($items);
        } else {
            $pkid = $attr::PK_ID;
            $where = "`{$pkid}`=" . $attr->$pkid;
        }

        try {
            return $this->doResult($this->_db->update($fields, $params, $where, $change));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param $attr
     * @return mixed
     * @throws \Exception
     */
    public function add($attr)
    {
        if (empty($attr) || is_array($attr)) {
            $entity = new $this->entity;
            $entity->create($attr);
        } elseif (is_object($attr)) {
            $entity = $attr;
        }
        try {
            return $this->doResult($this->_db->add($entity, $entity->getFields()));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param $where
     * @return mixed
     * @throws DaoException
     * @throws \Exception
     */
    public function remove($where)
    {
        if (empty($where)) {
            throw new DaoException('remove where empty', ERROR::REMOVE_WHERE_EMPTY);
        }

        try {
            return $this->doResult($this->_db->remove($this->parseWhere($where)));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }


    /**
     * @param array $items
     * @param string $fields
     * @param null $orderBy
     * @param null $start
     * @param null $limit
     * @return mixed
     * @throws \Exception
     */
    public function fetchArray(array $items = [], $fields = "*", $orderBy = null, $start = null, $limit = null)
    {
        try {
            if (empty($items)) {
                return $this->doResult($this->_db->fetchArray(1, $fields, $orderBy, $start, $limit));
            }
            return $this->doResult($this->_db->fetchArray($this->parseWhere($items), $fields, $orderBy, $start, $limit));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }  
    }

    /**
     * @param array $items
     * @return mixed
     * @throws \Exception
     */
    public function fetchCount($items = [])
    {
        try {
            return $this->doResult($this->_db->fetchCount($this->parseWhere($items)));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param array $items
     * @param string $fields
     * @return mixed
     * @throws \Exception
     */
    public function fetchOne($items = [], $fields = "*")
    {
        try {
            return $this->doResult($this->_db->fetchEntity($this->parseWhere($items), null, $fields));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param array $items
     * @return mixed
     * @throws \Exception
     */
    public function fetchByUnion($items = [])
    {
        $fields = "";
        $tables = "";
        $dbname = ZConfig::getField('pdo', $this->_dbTag, 'dbname');
        foreach ($items['fields'] as $table => $fieldArr) {
            foreach ($fieldArr as $field) {
                $fields .= "{$table}.{$field},";
            }
            $tables .= "{$dbname}.$table,";
        }
        $fields = rtrim($fields, ',');
        $tables = rtrim($tables, ',');
        $wheres = "1";

        foreach ($items['where'] as $item) {
            $wheres .= " and " . $item;
        }

        $order = "";
        if (!empty($items['order'])) {
            $order = $items['order'];
        }

        $sql = "select {$fields} from {$tables} where {$wheres}{$order}";

        try {
            return $this->doResult($this->fetchBySql($sql));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param $sql
     * @return mixed
     * @throws \Exception
     */
    public function fetchBySql($sql)
    {
        try {
            return $this->doResult($this->_db->fetchBySql($sql));
        } catch (\Exception $e) {
            Log::info([$this->_db->getLastSql()], 'sql_error');
            throw $e;
        }
    }

    /**
     * @param $result
     * @return mixed
     */
    private function doResult($result)
    {
        Log::info([$this->_db->getLastSql()], 'sql');
        return $result;
    }

    /**
     * 
     */
    public function checkPing()
    {
        if (!empty(self::$_dbs)) {
            foreach (self::$_dbs as $db) {
                $db->checkPing();
            }
        }
    }

}