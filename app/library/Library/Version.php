<?php
namespace SupAgent\Library;

use SupAgent\Exception\Exception;

class Version
{
    protected $file_name = PATH_ROOT . '/.version';
    protected $last_modified;

    public function __construct($file_name = null)
    {
        if ($file_name)
        {
            $this->file_name = $file_name;
        }

        if (($this->last_modified = filemtime($this->file_name)) === false)
        {
            throw new Exception("无法读取版本文件修改时间");
        }
    }

    public function hasChanged()
    {
        clearstatcache(true, $this->file_name);

        if ($this->last_modified !== filemtime($this->file_name))
        {
            return true;
        }

        return false;
    }


}