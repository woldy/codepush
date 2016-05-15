<?php
/*
  配置文件
*/
return [
  "local_path" => "F:\\test",  //本地目录
  "local_tmp_path"=>"F:\\tmp",  //本地临时目录
  "remote_path" => "/home/wwwroot/test.woldy.net/",  //远程目录
  "remote_backup_path" => "/home/wwwroot/backup/codepush",  //远程备份目录
  "remote_tmp_path"=>"/tmp", //远程临时目录
  "exclude_path" => [
      ".git",
  ],
  "push_list"=>[ //若非空则只传输列表内文件
      // 'index.php'
  ],
  "remote_server" => [
    [
      "ip" => "",
      "port"=>"22",
      "user" => "root"
    ]
  ],
];