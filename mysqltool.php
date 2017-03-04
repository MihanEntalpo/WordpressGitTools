#!/usr/bin/php
<?php

ob_implicit_flush(true);

$command = parseCmdLine($argv);

$actionFunction = "action" . ucfirst($command['action']);

if (function_exists($actionFunction))
{
    $actionFunction($command);
}
else
{
    echo "Unknown action: " . $command['action'] . ", type '" . $argv[0] . " help' to get usage info\n";
}


function actionHelp($command)
{
    $file = $command['file'];
    echo "Usage: \n";
    echo $file . " help                  - display this help\n";
    echo $file . " dump local            - dump local database to sql file\n";
    echo $file . " dump local --filename - dump local database to sql file and output just filename\n";
    echo $file . " dump remote           - dump local database to sql file\n";
    echo $file . " dump both             - dump local and remote databases to sql files\n";
    echo $file . " dump local  --copy    - dump local database to sql file, and copy it to remote side\n";
    echo $file . " dump remote --copy    - dump remote database to sql file, and copy it yo remote side\n";
    echo $file . " dump both --copy      - dump local and remote databases, and copy it to each other side\n";
    echo $file . " restore --file ./filename.sql - restore sql file to local database\n";
    echo $file . " pull                  - replace local database with remote one, dumping both DBs automatically\n";
    echo $file . " push                  - replace remote database with local one, dumping both DBs automatically\n";
}

function actionRestore($command)
{


    $config = getConfig();

    if (!isset($command['params']['file']))
    {
        die("--file /path/to/file.sql should be specified\n");
    }

    $returnFileName = gisset($command['keywords']['filename']);

    $filename = $command['params']['file'];
    if ($filename == "1") $filename = "";
    if (!file_exists($filename))
    {
        die("--file /path/to/file.sql should be specified, specified file '$filename' not found!\n");
    }

    echo "Dumping db for backup...\n";

    actionDump(array(
        "keywords" => array("local"=>true)
    ));

    echo "Restoring dump file '$filename' .... ";

    $cmd = "mysql -u {$config['DB_USER']} -p{$config['DB_PASSWORD']} -D {$config['DB_NAME']} < $filename";

    exec($cmd, $output, $return_var);
    if ($return_var == 0)
    {
        if (!$returnFileName) echo "done\n";
    }
    else
    {
        echo "\n";
        echo "Error on restore dump file\n";
        echo "Command executed: $cmd\n";
        echo "Return code: " . $return_var . "\n";
        echo "Output: \n";
        echo join("\n", $output) . "\n";
        die;
    }

}

function actionDump($command)
{
    ob_implicit_flush(true);

    $result = array();

    $needDumpLocal = (isset($command['keywords']['local']) || isset($command['keywords']['both']));
    $needDumpRemote = (isset($command['keywords']['remote']) || isset($command['keywords']['both']));
    $needCopy = !!gisset($command['params']['copy']);
    $returnFileName = !!gisset($command['params']['filename']);

    $config = getConfig();
    $ssh_host = $config['remote']['sshhost'];
    $ssh_folder = $config['remote']['folder'];

    if (!$needDumpLocal && !$needDumpRemote)
    {
        echo "Error: you should specify 'local', 'remote' or 'both' keyword when using 'dump' command\n";
    }

    if ($needDumpLocal)
    {

        $conf = getConfig();
        $local_filename = "{$conf['DUMP_DIR']}/local-" . date('Y-m-d_H-i-s') . ".sql";
        $cmd = "mysqldump --add-drop-table --databases {$conf['DB_NAME']} -u{$conf['DB_USER']} -p{$conf['DB_PASSWORD']} > {$local_filename}";

        $result['dump_local_filename'] = $local_filename;

        if (!$returnFileName)
        {
            echo "Dumping local database into {$local_filename} .... ";
        }
        exec($cmd);
        if (!$returnFileName)
        {
            echo "done!\n";
        }
        if ($returnFileName)
        {
            echo $local_filename . "\n";
        }

        if ($needCopy)
        {

            $local_filename_copy = preg_replace('#^(.*?)/local-([^/]+\.sql)$#', $ssh_folder . '/mysqldump/remote-$2', $local_filename);
            $result['dump_local_copy_filename'] = $local_filename_copy;
            if (!$returnFileName) echo "Local file would be copied to $local_filename_copy\n";
            checkSshOrDie();
            $cmd = "scp " . $local_filename . " " . $ssh_host . ":" . $local_filename_copy;
            if (!$returnFileName) echo "Copying file to remote .... ";
            exec($cmd, $output, $return_var);
            if ($return_var == 0)
            {
                if (!$returnFileName) echo "done\n";
            }
            else
            {
                echo "\n";
                echo "Error on copying local file to remote\n";
                echo "Command executed: $cmd\n";
                echo "Return code: " . $return_var . "\n";
                echo "Output: \n";
                echo join("\n", $output) . "\n";
                die;
            }

        }
    }

    if ($needDumpRemote)
    {

        checkSshOrDie();

        $remote_cmd = $ssh_folder . "/mysqltool.php dump local --filename";
        $cmd = "ssh $ssh_host '$remote_cmd'";
        if (!$returnFileName) echo "Dumping remote database .... ";
        exec($cmd, $output, $return_var);

        if ($return_var == 0)
        {
            if (!$returnFileName) echo "done!\n";
            $remote_filename = $output[0];

            $result['dump_remote_filename'] = $remote_filename;

            if (!$returnFileName)
            {
                echo "Remote filename: " . $remote_filename . "\n";
            }
            else
            {
                echo $remote_filename . "\n";
            }
        }
        else
        {
            echo "\n";
            echo "Error on executing remote dump command\n";
            echo "Remote command executed: $cmd\n";
            echo "Return code: " . $return_var . "\n";
            echo "Output: \n";
            echo join("\n", $output) . "\n";
            die;
        }

        if ($needCopy)
        {

            $remote_filename_copy = preg_replace('#^(.*?)/local-([^/]+\.sql)$#', $config['DUMP_DIR'] . '/remote-$2', $remote_filename);

            $result['dump_remote_copy_filename'] = $remote_filename_copy;

            if (!$returnFileName) echo "Remote file would be copied to $remote_filename_copy\n";

            $cmd = "scp " . $ssh_host . ":" . $remote_filename . " " . $remote_filename_copy;
            if (!$returnFileName) echo "Copying file to local .... ";
            exec($cmd, $output, $return_var);
            if ($return_var == 0)
            {
                if (!$returnFileName) echo "done\n";
            }
            else
            {
                echo "\n";
                echo "Error on copying remote file to local\n";
                echo "Command executed: $cmd\n";
                echo "Return code: " . $return_var . "\n";
                echo "Output: \n";
                echo join("\n", $output) . "\n";
                die;
            }

        }

    }

    return $result;

}

function actionPull()
{
    $config = getConfig();
    $ssh_host = $config['remote']['sshhost'];
    $ssh_folder = $config['remote']['folder'];

    echo "Pull: Dumping remote and local databases, and copying them to each other side...\n";

    $results = actionDump(array(
        "action" => "dump",
        "keywords" => array("both" => 1),
        'params' => array("copy" => 1)
    ));

    echo "Pull: Restoring remote database on the local side...\n";

    actionRestore(array(
        "action" => "restore",
        "params" => array(
            "file" => $results['dump_remote_copy_filename']
        ),
        "keywords" => array()
    ));

    echo "Pull done!\n";
}


function actionPush()
{
    $config = getConfig();
    $ssh_host = $config['remote']['sshhost'];
    $ssh_folder = $config['remote']['folder'];

    echo "Push: Dumping remote and local databases, and copying them to each other side...\n";

    $results = actionDump(array(
        "action" => "dump",
        "keywords" => array("both" => 1),
        'params' => array("copy" => 1)
    ));

    echo "Push: Restoring local database on the remote side...\n";

    $remote_cmd = $ssh_folder . "/mysqltool.php restore --file " . $results['dump_local_copy_filename'];
    $cmd = "ssh $ssh_host '$remote_cmd'";

    exec($cmd, $output, $return_var);

    if ($return_var == 0)
    {
        echo "Push done!\n";
    }
    else
    {
        echo "\n";
        echo "Error on executing remote restore command\n";
        echo "Remote command executed: $cmd\n";
        echo "Return code: " . $return_var . "\n";
        echo "Output: \n";
        echo join("\n", $output) . "\n";
        die;
    }
}

function gisset(&$variable, $default=null)
{
    return isset($variable) ? $variable : $default;
}

function checkSshOrDie()
{
    if (!checkSsh())
    {
        $config = getConfig();
        $ssh_host = $config['remote']['sshhost'];
        echo "Error: ssh couldn't connect to remote host '$ssh_host' in automatic mode, please ensure, that you able to login to it without password (by key)\n";
        die;
    }
}

function checkSsh()
{
    static $state = null;
    if (is_null($state))
    {
        $config = getConfig();
        $ssh_host = $config['remote']['sshhost'];
        $cmd = "ssh -q -o BatchMode=yes $ssh_host exit";
        exec($cmd, $output, $return_var);
        $state = $return_var == "0";
    }
    return $state;
}

function parseCmdLine($argv)
{
    $command = array(
        "file" => $argv[0],
        "action" => "help",
        "params" => array(),
        "keywords" => array()
    );

    $getParamName = function($argument, &$paramName)
    {
        if (preg_match('#^-(?P<paramShort>[a-zA-Z0-9_]{1})$|^--(?P<paramLong>[a-zA-Z0-9_]+)$#', $argument, $matches))
        {
            $paramName = $matches['paramShort'] ?: $matches['paramLong'];
            $res = true;
        }
        else
        {
            $paramName = null;
            $res = false;
        }
        return $res;
    };

    $lastParam = null;
    foreach($argv as $num=>$arg)
    {
        if ($num == 1)
        {
            $command["action"] = $arg;
        }
        else if ($num > 1)
        {
            if ($lastParam)
            {
                if ($getParamName($arg, $paramName))
                {
                    $command["params"][$lastParam] = 1;
                    $lastParam = $paramName;
                }
                else
                {
                    $command["params"][$lastParam] = $arg;
                    $lastParam = null;
                }
            }
            else
            {
                if ($getParamName($arg, $paramName))
                {
                    $lastParam = $paramName;
                }
                else
                {
                    if (preg_match('#^[a-zA-Z0-9_]+$#', $arg))
                    {
                        $command['keywords'][$arg] = 1;
                    }
                }
            }
        }
    }
    if ($lastParam)
    {
        $command["params"][$lastParam] = 1;
    }
    return $command;
}

/**
 * Получить конфигурацию MYSQL из файлов wp-config.php и mysqltool.conf.php
 * в виде массива, содержащего
 * DB_NAME, DB_USER, DB_PASSWORD, DUMP_DIR, и remote
 * @staticvar type $conf
 * @return type
 */
function getConfig()
{
    static $conf=null;

    if (is_null($conf))
    {

        $_SERVER['HTTP_HOST'] = "null";

        StreamWrapper::$ignoredFiles = array(__DIR__ . "/www/wp-settings.php");
        StreamWrapper::wrap();

        require_once(__DIR__ . "/www/wp-config.php");

        StreamWrapper::unwrap();

        $conf = array(
            "DB_NAME" => DB_NAME,
            "DB_USER" => DB_USER,
            "DB_PASSWORD" => DB_PASSWORD,
            "DUMP_DIR" => __DIR__ . "/mysqldump"
        );

        $conf = array_replace($conf, require(__DIR__ . "/mysqltool.conf.php"));
    }

    return $conf;
}

/**
 * Класс для преопределения файловых операций, позволяющий игнорировать подключение определённых файлов,
 * указанных в массиве $ingoredFiles
 */
class StreamWrapper
{
	const PROTOCOL = "file";
    const STREAM_OPEN_FOR_INCLUDE = 128;

    public $context;
    public $resource;
    public static $ignoredFiles = array();

    static function wrap()
    {
        foreach(self::$ignoredFiles as &$file)
        {
            if ($file != realpath($file) && realpath($file) != "")
            {
                $file = realpath($file);
            }
        }
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, __CLASS__);
    }

    static function unwrap()
    {
        stream_wrapper_restore(self::PROTOCOL);
    }

    function stream_open($path, $mode, $options, &$openedPath)
    {
        $this->unwrap();

        if (in_array(realpath($path), self::$ignoredFiles))
        {
            $path = 'php://memory';
        }

		if (isset($this->context)) {
            $this->resource = fopen($path, $mode, $options, $this->context);
        } else {
            $this->resource = fopen($path, $mode, $options);
        }

        $this->wrap();

        return $this->resource !== false;
    }

    function stream_close()
    {
		$res = fclose($this->resource);

        return $res;
    }

    function stream_eof()
    {
        return feof($this->resource);
    }

    function stream_flush()
    {
        return fflush($this->resource);
    }

    function stream_read($count)
    {
        return fread($this->resource, $count);
    }

    function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->resource, $offset, $whence) === 0;
    }

    function stream_stat()
    {
        return fstat($this->resource);
    }

    function stream_tell()
    {
        return ftell($this->resource);
    }

    function url_stat($path, $flags)
    {
        $this->unwrap();
        $result = @stat($path);
        $this->wrap();
        return $result;
    }

    function dir_closedir()
    {
        $this->unwrap();
        closedir($this->resource);
        $this->wrap();
        return true;
    }

    function dir_opendir($path, $options)
    {
        $this->unwrap();
        if (isset($this->context)) {
            $this->resource = opendir($path, $this->context);
        } else {
            $this->resource = opendir($path);
        }
        $this->wrap();
        return $this->resource !== false;
    }

    function dir_readdir()
    {
        return readdir($this->resource);
    }

    function dir_rewinddir()
    {
        rewinddir($this->resource);
        return true;
    }

    function mkdir($path, $mode, $options)
    {
        $this->unwrap();
        if (isset($this->context)) {
            $result = mkdir($path, $mode, $options, $this->context);
        } else {
            $result = mkdir($path, $mode, $options);
        }
        $this->wrap();
        return $result;
    }

    function rename($path_from, $path_to)
    {
        $this->unwrap();
        if (isset($this->context)) {
            $result = rename($path_from, $path_to, $this->context);
        } else {
            $result = rename($path_from, $path_to);
        }
        $this->wrap();
        return $result;
    }

    function rmdir($path, $options)
    {
        $this->unwrap();
        if (isset($this->context)) {
            $result = rmdir($path, $this->context);
        } else {
            $result = rmdir($path);
        }
        $this->wrap();
        return $result;
    }

    function stream_cast($cast_as)
    {
        return $this->resource;
    }

    function stream_lock($operation)
    {
        return flock($this->resource, $operation);
    }

    function stream_set_option($option, $arg1, $arg2)
    {
        switch ($option) {
            case STREAM_OPTION_BLOCKING:
                return stream_set_blocking($this->resource, $arg1);
            case STREAM_OPTION_READ_TIMEOUT:
                return stream_set_timeout($this->resource, $arg1, $arg2);
            case STREAM_OPTION_WRITE_BUFFER:
                return stream_set_write_buffer($this->resource, $arg1);
            case STREAM_OPTION_READ_BUFFER:
                return stream_set_read_buffer($this->resource, $arg1);
            case STREAM_OPTION_CHUNK_SIZE:
                return stream_set_chunk_size($this->resource, $arg1);
        }
    }

    function stream_write($data)
    {
        return fwrite($this->resource, $data);
    }

    function unlink($path)
    {
        $this->unwrap();
        if (isset($this->context)) {
            $result = unlink($path, $this->context);
        } else {
            $result = unlink($path);
        }
        $this->wrap();
        return $result;
    }

    function stream_metadata($path, $option, $value)
    {
        $this->unwrap();
        switch ($option) {
            case STREAM_META_TOUCH:
                if (empty($value)) {
                    $result = touch($path);
                } else {
                    $result = touch($path, $value[0], $value[1]);
                }
                break;
            case STREAM_META_OWNER_NAME:
            case STREAM_META_OWNER:
                $result = chown($path, $value);
                break;
            case STREAM_META_GROUP_NAME:
            case STREAM_META_GROUP:
                $result = chgrp($path, $value);
                break;
            case STREAM_META_ACCESS:
                $result = chmod($path, $value);
                break;
        }
        $this->wrap();
        return $result;
    }

    function stream_truncate($new_size)
    {
        return ftruncate($this->resource, $new_size);
    }
}

