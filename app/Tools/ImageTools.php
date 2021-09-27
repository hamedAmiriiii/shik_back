<?php


namespace App\Tools;


use Illuminate\Support\Facades\Storage;

class ImageTools
{
    public static function saveFile(string $path, $content, bool $isFullPath = false) : string
    {
        if ($isFullPath) {
            Storage::put($path, $content,'public');
            return $path;
        } else {
            $date = jdate();
            $fullPath = $date->getYear() . "/" . $date->getMonth() . "/" . $date->getDay();
            $path = str_starts_with($path, "/") ? $path : "/" . $path;
            $fullPath .= $path;
            Storage::put('public/'.$fullPath, $content,'public');
            return $fullPath;
        }
    }
}
