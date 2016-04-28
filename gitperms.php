#!/usr/bin/php
<?php

namespace RGooding\GitPerms;

/*
This script recursively saves and restores the ownership and
permissions of every file in the directory containing the script
*/

class PermsConfig
{
  public static $permissionsFile = '.gitperms';
  public static $defaultUid = 0;
  public static $defaultGid = 0;
  public static $defaultDirMode = 0755;
  public static $defaultFileMode = 0644;
  public static $ignoreDirs = ['.git'];
  public static $ignoreFiles = ['.gitperms'];
}

class GitPerms
{
  private $_commandName;

  public function usage()
  {
    echo "Usage: " . $this->_commandName . " save|restore [--dry-run] [directory]\n";
    echo "  save      : Save the permissions\n";
    echo "  restore   : Restore the permissions\n";
    echo "  directory : The root directory to start from\n";
    echo "              Defaults to the current working directory\n";
    echo "\n";
    echo "  --dry-run : Don't actually save or restore the permissions\n";
    echo "\n";
    die;
  }

  public function getFilePermissions($file)
  {
    $info = stat($file);
    if(!$info)
    {
      throw new \Exception('Could not stat ' . $file);
    }
    $perms = [
      'uid'  => $info['uid'],
      'gid'  => $info['gid'],
      'mode' => decoct($info['mode'] & 07777)
    ];
    return $perms;
  }

  public function saveDirPermissions($dir, $dryRun)
  {
    $dir = rtrim($dir, '/');
    $permFile = $dir . '/' . PermsConfig::$permissionsFile;

    $allPerms = ['.' => $this->getFilePermissions($dir)];
    $dh = opendir($dir);
    if(!$dh)
    {
      throw new \Exception('Could not open dir ' . $dir . ' to get files');
    }
    while($file = readdir($dh))
    {
      if(!in_array($file, PermsConfig::$ignoreFiles))
      {
        $fullPath = $dir . '/' . $file;
        if(is_file($fullPath))
        {
          $allPerms[$file] = $this->getFilePermissions($fullPath);
        }
      }
    }
    closedir($dh);

    if(!$dryRun)
    {
      file_put_contents($permFile, json_encode($allPerms));
    }
    echo "Saved permissions for " . $dir . "\n";
  }

  public function restoreDirPermissions($dir, $dryRun)
  {
    $dir = rtrim($dir, '/');
    $permFile = $dir . '/' . PermsConfig::$permissionsFile;
    $allPerms = file_exists($permFile) ? json_decode(
      file_get_contents($permFile),
      true
    ) : [];

    foreach($allPerms as $file => $perms)
    {
      if(!isset($perms['uid']))
      {
        $perms['uid'] = PermsConfig::$defaultUid;
      }
      if(!isset($perms['gid']))
      {
        $perms['gid'] = PermsConfig::$defaultGid;
      }

      if($file == '.')
      {
        $fullPath = $dir;
        if(!isset($perms['mode']))
        {
          $perms['mode'] = PermsConfig::$defaultDirMode;
        }
      }
      else
      {
        $fullPath = $dir . '/' . $file;
        if(in_array($file, PermsConfig::$ignoreFiles) || (!file_exists(
            $fullPath
          ))
        )
        {
          continue;
        }
        if(!isset($perms['mode']))
        {
          $perms['mode'] = PermsConfig::$defaultFileMode;
        }
      }

      $currentPerms = $this->getFilePermissions($fullPath);

      $modifyMsg = [];
      if($currentPerms['uid'] != $perms['uid'])
      {
        $modifyMsg[] = 'Owner=' . $perms['uid'];
        if(!$dryRun)
        {
          chown($fullPath, $perms['uid']);
        }
      }
      if($currentPerms['gid'] != $perms['gid'])
      {
        $modifyMsg[] = 'Group=' . $perms['gid'];
        if(!$dryRun)
        {
          chgrp($fullPath, $perms['gid']);
        }
      }
      if($currentPerms['mode'] != $perms['mode'])
      {
        $modifyMsg[] = 'Mode=' . $perms['mode'];
        if(!$dryRun)
        {
          chmod($fullPath, octdec($perms['mode']));
        }
      }

      if(count($modifyMsg))
      {
        echo "Restored " . $fullPath . " : " . implode(', ', $modifyMsg) . "\n";
      }
    }
  }

  public function processDir($dir, $forSave = true, $dryRun = true)
  {
    $dir = rtrim($dir, '/');

    if($forSave)
    {
      $this->saveDirPermissions($dir, $dryRun);
    }
    else
    {
      $this->restoreDirPermissions($dir, $dryRun);
    }

    $dh = opendir($dir);
    if(!$dh)
    {
      throw new \Exception('Could not open directory ' . $dir);
    }

    $ignoreList = array_merge(['.', '..'], PermsConfig::$ignoreDirs);

    while($file = readdir($dh))
    {
      $fullPath = $dir . '/' . $file;
      if(is_dir($fullPath) && (!in_array($file, $ignoreList)))
      {
        $this->processDir($fullPath, $forSave, $dryRun);
      }
    }
  }

  public function main($argc, $argv)
  {
    $this->_commandName = $argv[0];

    if(($argc < 2) || ($argc > 4)
      || in_array('-h', $argv) || in_array('--help', $argv)
    )
    {
      $this->usage();
    }

    $rootDir = getcwd();

    switch($argv[1])
    {
      case "save":
      case "restore":
        $mode = $argv[1];
        break;
      default:
        $this->usage();
        die;
    }

    $dryRun = false;
    if($argc >= 3)
    {
      if($argv[2] == '--dry-run')
      {
        $dryRun = true;
        array_shift($argv);
      }
      if(isset($argv[2]))
      {
        if(file_exists($argv[2]) && is_dir($argv[2]))
        {
          $rootDir = $argv[2];
        }
      }
      if(isset($argv[3]))
      {
        $this->usage();
      }
    }

    echo date('d/m/Y H:i:s') . " Started " . $argv[1] . "\n";
    $this->processDir($rootDir, $mode == 'save', $dryRun);
    echo date('d/m/Y H:i:s') . " Completed " . $argv[1] . "\n";
  }
}

(new GitPerms())->main($argc, $argv);
