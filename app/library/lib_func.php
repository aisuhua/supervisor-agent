<?php

/**
 * 将字节格式化成可读形式
 *
 * @param $bytes
 * @param int $length
 * @param string $max_unit
 * @return string
 */
function size_format($bytes, $length = 2, $max_unit = '')
{
    $max_unit = strtoupper($max_unit);
    $unit = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB', 'DB', 'NB');
    $extension = $unit[0];
    $max = count($unit);

    for ($i = 1; (($i < $max) && ($bytes >= 1024) && $max_unit != $unit[$i - 1]); $i++)
    {
        $bytes /= 1024;
        $extension = $unit[$i];
    }

    return round($bytes, $length) . $extension;
}

function print_cli(...$args)
{
    echo '[' . date('Y-m-d H:i:s'), '] ' . implode('', $args), PHP_EOL;
}

function print_err(...$args)
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . implode('', $args) . PHP_EOL);
}

function build_ini_string(array $parsed)
{
    $ini = '';
    foreach ($parsed as $key => $item)
    {
        $ini .= '[' . $key . ']' . PHP_EOL;
        foreach ($item as $k => $v)
        {
            $ini .= $k . '=' . $v . PHP_EOL;
        }
    }

    return $ini;
}

function frread($fp, $bytes)
{
    fseek($fp, -$bytes, SEEK_END);

    return fread($fp, $bytes);
}

function echoLog($log_file, $file_size)
{
    if ($file_size == 0)
    {
        $file_size = filesize($log_file);
    }

    if ($file_size == 0)
    {
        return '';
    }

    $fp = fopen($log_file, 'r');
    echo frread($fp, $file_size);
}
