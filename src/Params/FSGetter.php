<?php namespace DBDiff\Params;

use DBDiff\Exceptions\FSException;
use Symfony\Component\Yaml\Yaml;


class FSGetter implements ParamsGetter {

    function __construct($params) {
        $this->params = $params;
    }

    public function setSourceDB($db) {
        $this->sourceDB = $db;
        return $this;
    }
    public function setTargetDB($db) {
        $this->targetDB = $db;
        return $this;
    }

    public function getParams() {
        $params = new \StdClass;
        try {
            $config['server1'] = $this->targetDB;
            $config['server2'] = $this->sourceDB;
            $config['type'] = 'schema';
            $config['include'] = 'up';
            $config['nocomments'] = true;
            foreach ($config as $key => $value) {
                $this->setIn($params, $key, $value);
            }
        } catch (Exceptions $e) {
            throw new FSException("Error parsing config file");
        }
        
        return $params;
    }

    protected function getFile() {
        $configFile = false;

        if (isset($this->params->config)) {
            $configFile = $this->params->config;
            if (!file_exists($configFile)) {
                throw new FSException("Config file not found");
            }
        } else {
            if (file_exists(getcwd() . '/.dbdiff')) {
                $configFile = getcwd() . '/.dbdiff';
            }
            if (file_exists(getcwd() . '/..' . '/.dbdiff')) {
                $configFile = getcwd() . '/..' . '/.dbdiff';
            }
        }

        return $configFile;
    }

    protected function setIn($obj, $key, $value) {
        if (strpos($key, '-') !== false) {
            $parts = explode('-', $key);
            $array = &$obj->$parts[0];
            $array[$parts[1]] = $value;
        } else {
            $obj->$key = $value;
        }
    }

}
