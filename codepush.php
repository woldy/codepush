<?php
error_reporting(0);
class CodePush
{
	private $config=[];
	private $filelist=[];
	private $filelist_t=[];
	private $filelist_r=[];
	private $filelist_b=[];
	private $pathlist=[];
	private $pathlist_t=[];
	private $pathlist_r=[];
	private $pathlist_b=[];
	private $srcpath='';
	private $tmppath='';
	private $localnewpath='';
	private $log=[];
	private $taskid=0;
	private $conn;
	private $sftp;
	private $basename='';
	private $server;
	function __construct($cfgPath="codepush.config.php"){
		if(file_exists($cfgPath)){
			$this->config=require($cfgPath);
			$this->srcpath=$this->config['local_path'];//代码源目录
			$this->tmppath=$this->config['local_tmp_path'];//临时目录
			$this->localnewpath=$this->tmppath.'/'.basename($this->srcpath);//临时目录代码路径
			$this->basename=basename($this->srcpath);
		}else{
			$this->error("cannot find $cfgPath\n");
		}
	}


	function start($cmd){
		if(empty($cmd)){
			$this->output('usage: ','red');
			echo "php codepush.php  push|back|shell|log\n";
		}else if($cmd=='push'){
			$this->push();
		}else if($cmd=='back'){
			$this->back();
		}else if($cmd=='log'){
			$this->log();
		}else if($cmd=='shell'){
			$this->remote_shell();
		}else{
			$this->output('usage: ','red');
			echo "php codepush.php  push|back|shell|log\n";
		}
	}

	/**
	 * 欢迎信息
	 * @Author   Woldy
	 * @DateTime 2016-05-13T18:35:11+0800
	 * @return   [type]                   [description]
	 */
	function welcome(){
		$this->tip("|-------------------------------|\n");
		$this->tip("|-------------------------------|\n");
		$this->tip("|---codepush script by woldy.---|\n");
		$this->tip("|--------king@woldy.net---------|\n");
		$this->tip("|-------------------------------|\n");
		$this->tip("|------------v0.1---------------|\n");
		$this->tip("|-------------------------------|\n");
		$this->tip("|------------start--------------|\n");
		$this->tip("|--------------+----------------|\n");
		$this->tip("|--------------v----------------|\n");
		$this->tip("\n\n\n");
	}

	/**
	 * 推送远程代码
	 * @Author   Woldy
	 * @DateTime 2016-05-13T18:48:45+0800
	 * @return   [type]                   [description]
	 */
	public function push(){
		$this->putlog('push');
		$this->shell("clear",false);
		$this->welcome();
		$this->checkConfig();
		$this->initFile();
		$this->sureList();
		$this->go();
	}


	/**
	 * 检查配置文件
	 * @Author   Woldy
	 * @DateTime 2016-05-13T18:33:42+0800
	 * @param    string                   $type [description]
	 * @return   [type]                         [description]
	 */
	function checkConfig($type='local'){
		$this->tip("checking $type config...\n");
		$conf_list=['local_path','local_tmp_path','remote_path','remote_backup_path','remote_tmp_path'];
		
		foreach ($conf_list as $conf) {
			if(!isset($this->config[$conf])){
				$this->error("cannot find {$conf}!\n");
			}
		}
		if($type=='local'){
			if(!file_exists($this->config['local_path'])){
				$this->error("cannot find local_path!\n");
			}

			if(!file_exists($this->config['local_tmp_path']) ||strlen($this->config['local_tmp_path'])<2){
				if(!mkdir($this->config['local_tmp_path'],0777,true)){
					$this->error("cannot find local_tmp_path!\n");
				}
			}			
		}else{
			if(!ssh2_sftp_lstat($this->sftp, $this->config['remote_path'])){
				if(!ssh2_sftp_mkdir($this->sftp,$this->config['remote_path'],fileperms($this->config['local_path']),true)){
					$this->error("cannot create remote path!\n");
				}
			}

			if(!ssh2_sftp_lstat($this->sftp, $this->config['remote_backup_path'])){
				if(!ssh2_sftp_mkdir($this->sftp,$this->config['remote_backup_path'],0777,true)){
					$this->error("cannot create remote backup_path!\n");
				}
			}

			if(!ssh2_sftp_lstat($this->sftp, $this->config['remote_tmp_path'])){
				if(!ssh2_sftp_mkdir($this->sftp,$this->config['remote_tmp_path'],0777,true)){
					$this->error("cannot create remote tmp path!\n");
				}
			}
		}
		$this->success("check $type config done\n");
	}

	/**
	 * 目录初始化
	 * @Author   Woldy
	 * @DateTime 2016-05-13T14:31:05+0800
	 * @return   [type]                   [description]
	 */
	function initFile(){
		//检查临时目录是否存在代码同名目录，若存在则备份
		if(file_exists($this->localnewpath)){
			$this->tip("backuping last code...\n");
			$this->shell('mv "'.$this->localnewpath.'" "'.$this->tmppath.'/beforetask'.$this->taskid.'"');
			$this->success("backup last code done\n");
		}

		//将要发布的代码复制到临时目录
		$this->tip("cpoying new code...\n");
		$this->shell('cp -r '.$this->srcpath.' '.$this->tmppath);	
		$this->success("cpoy new code done\n");

		//排除目录
		$this->tip("removing exclude path...\n");
		if(isset($this->config['exclude_path'])){
			foreach ($this->config['exclude_path'] as $path) {
				$this->tip("removing ".$this->localnewpath.'/'.$path." \n");
				$this->shell('rm -rf '.$this->localnewpath.'/'.$path);
			}
		}
		$this->success("remove exclude done\n");


	}

	/**
	 * 生成文件列表，若存在push_list则以pushlist为准，否则以localpath为准
	 * @Author   Woldy
	 * @DateTime 2016-05-13T14:30:44+0800
	 * @return   [type]                   [description]
	 */
	function sureList(){
		$this->tip("createing push file list...\n");
		if(!empty($this->config['push_list'])){
			foreach ($this->config['push_list'] as $path) {
				$this->getList($this->localnewpath.'/'.$path);
			}
		}else{
			$this->getList($this->localnewpath);
		}
		$this->success("create push file list done\n");

		$pathlist=implode("\n     ", $this->pathlist);
		$this->warning("push pathlist:\n");
		$this->tip('    '.$pathlist."\n");

		$continue=trim(strtoupper($this->input('are your sure to push those pathlist? (y/n)','yellow')));
		if($continue=='N'){
			$this->error(" close script\n");
		}else{
			$this->tip(" continue next step...\n");
		}

		$filelist=implode("\n     ", $this->filelist);
		$this->warning("push filelist:\n");
		$this->tip('    '.$filelist."\n");

		$continue=trim(strtoupper($this->input('are your sure to push those filelist? (y/n)','yellow')));
		if($continue=='N'){
			$this->error(" close script\n");
		}else{
			$this->tip(" continue next step...\n");
		}



	}


	/**
	 * 递归获取本地文件列表
	 * @param  [type] $path [description]
	 * @return [type]       [description]
	 */
	function getList($path){
		if(!file_exists($path)) return;
		if(is_dir($path)){
			if(!in_array($path,$this->pathlist)){
				array_push($this->pathlist,$path);
			}
     		if ($dh = opendir($path)){
        		while (($file = readdir($dh)) !== false){
     				if((is_dir($path."/".$file)) && $file!="." && $file!=".."){
     					array_push($this->pathlist,$path."/".$file);
     					$this->getList($path."/".$file);
     				}
					else{
         				if($file!="." && $file!=".."){
         					array_push($this->filelist,$path."/".$file);
      					}
     				}
        		}
        		closedir($dh);
     		}
   		}else{
   			array_push($this->filelist,$path);
   		}
	}


	/**
	 * 正式发送文件
	 * @return [type] [description]
	 */
	function go(){
		$server_list=$this->config['remote_server'];
		if(empty($server_list)){
			$this->error("please set remote server!\n");
		}
		foreach ($server_list as $server) {
			$conn=$this->login($server);
			if($conn){
				$this->conn=$conn;
				$this->sftp = ssh2_sftp($this->conn);
				$this->checkConfig('remote');
				$this->makePath();
				$this->sendfile();
			}
		}

		$this->success("\n\ntask{$this->taskid}: all is done!\n");
	}




	/**
	 * 创建远程传递/备份目录
	 * @Author   Woldy
	 * @DateTime 2016-05-13T18:34:36+0800
	 * @return   [type]                   [description]
	 */
	function makepath(){
		$backuppath=$this->config['remote_backup_path'].'/task'.$this->taskid.'/';
		foreach($this->pathlist as $path){
			$reallypath=$this->str_replace_once($this->config['local_tmp_path'],'',$path); //reallpath,替换本地临时目录为空
			$reallypath=$this->str_replace_once($this->basename,'',$reallypath); //reallpath,替换目录名为空
			$reallypath=str_replace('//','/',$reallypath);

			$remotenewpath=$this->config['remote_path'].$reallypath;
			$remotenewpath=str_replace('//','/',$remotenewpath);
			$backpath=$backuppath.$reallypath;
			$backpath=str_replace('//','/',$backpath);

			//$this->warning($reallypath."\n");
			//$this->warning($remotenewpath."\n");
			//$this->warning($backpath."\n");


			$this->tip("testing remote path $remotenewpath...\n");
			if(!ssh2_sftp_lstat($this->sftp,$remotenewpath)){
				if(!ssh2_sftp_mkdir($this->sftp,$remotenewpath,fileperms($path),true)){
					$this->YNgo("cannot create remote path:$remotenewpath");
				}else{
					$this->success("create remote path $remotenewpath done!\n");
				}
			}else{
				$this->success("test remote path $remotenewpath done!\n");
			}

			$this->tip("testing backup path $backpath...\n");
			if(!ssh2_sftp_mkdir($this->sftp,$backpath,fileperms($path),true)){
				$this->YNgo("cannot create remote path:$backpath");
			}else{
				$this->success("create remote path $backpath done!\n");
			}

			
		}
	}

	function sendfile(){
		$count=1;
		$backuppath=$this->config['remote_backup_path'].'/task'.$this->taskid.'/';

		foreach($this->filelist as $file){


			$reallyfile=$this->str_replace_once($this->config['local_tmp_path'],'',$file); //reallpath,替换本地临时目录为空
			$reallyfile=$this->str_replace_once($this->basename,'',$reallyfile); //reallpath,替换目录名为空
			$reallyfile=str_replace('//','/',$reallyfile);

			$remotenewfile=$this->config['remote_path'].$reallyfile;
			$remotenewfile=str_replace('//','/',$remotenewfile);
			$backfile=$backuppath.$reallyfile;
			$backfile=str_replace('//','/',$backfile);

			// $this->warning($reallyfile."\n");
			// $this->warning($remotenewfile."\n");
			// $this->warning($backfile."\n");



			$this->tip("backuping remote file : $backfile...\n");
			if(ssh2_sftp_lstat($this->sftp,$remotenewfile)){
				if(ssh2_sftp_rename($this->sftp,$remotenewfile,$backfile)){
					$this->success("backup remote file : $backfile done\n");
				}else{
					$this->warning("backup remote file : $backfile failed\n");
				}
			}else{
				$this->warning("remote file not need to backup: $remotenewfile...\n");
			}

			$this->tip("sending remote file : $remotenewfile...\n");
			if(ssh2_scp_send($this->conn,$file,$remotenewfile)){
				$this->success("send remote file : $remotenewfile done\n");
			}else{
				$this->warning("send remote file : $remotenewfile failed\n");
			}
			

			
		}
	}




	/**
	 * 回滚代码
	 * @Author   Woldy
	 * @DateTime 2016-05-13T18:49:14+0800
	 * @return   [type]                   [description]
	 */
	public function back(){
		$this->putlog('back');
		$this->log('push');
		$taskid=$this->taskid;

		$backtaskid=$this->input("please enter back taskid:");
		
		if(isset($this->log[$backtaskid]) && $this->log[$backtaskid]['cmd']=='push'){
			$this->config=json_decode($this->log[$backtaskid]['config'],true);
			$this->taskid=$taskid;
		}else{
			$this->error("no have this taskid...\n");
		}
	
		$server_list=$this->config['remote_server'];

		if(empty($server_list)){
			$this->error("please set remote server!\n");
		}
		foreach ($server_list as $server) {
			$conn=$this->login($server);
			if($conn){
				$this->conn=$conn;
				$this->sftp = ssh2_sftp($this->conn);
				$this->checkConfig('remote');
				$this->rollBack($backtaskid);
			}
		}
		$this->success("task{$this->taskid}: all is done!\n");		
	}



	/**
	 * 回滚
	 * @Author   Woldy
	 * @DateTime 2016-05-13T10:40:13+0800
	 * @return   [type]                   [description]
	 */
	function rollBack($backtaskid){
		$backuppath=$this->config['remote_backup_path'].'/task'.$backtaskid.'/';
		$targetpath=$this->config['remote_path'];
		$this->remote_exec("\cp -rf  $backuppath/*  $targetpath");
		$this->success("rollback success! \n");
	}

	function remote_shell(){
		$this->putlog('shell');
		$server_list=$this->config['remote_server'];
		
		if(empty($server_list)){
			$this->error("please set remote server!\n");
		}
		foreach ($server_list as $server) {
			$conn=$this->login($server);
			if($conn){
				$this->conn=$conn;
				//$this->sftp = ssh2_sftp($this->conn);
				$cmd='';
				while ($cmd!='quit') {
					$cmd=$this->input(">");
					if($cmd=='quit') return;
					$this->remote_exec($cmd);
				}
			}
		}
		$this->success("task{$this->taskid}: all is done!\n");		
	}


	/**
	 * 执行远程命令
	 * @param  [type] $cmd [description]
	 * @return [type]      [description]
	 */
	function remote_exec($cmd){
		$stream=ssh2_exec($this->conn,$cmd);
		stream_set_blocking($stream, true); 
		$errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
		$success=stream_get_contents($stream);
		$error=stream_get_contents($errorStream);
		fclose($errorStream);
		fclose($stream);
		if(!empty($error)){
			$this->warning($error."\n");
			$this->YNgo('it seems some thing wrong..');
		}else{
			if(empty($success)){
				return;
			}else{
				$this->success($success."\n");
			}
		}
	}




	/**
	 * 登录远程主机
	 * @param  [type] $server [description]
	 * @return [type]         [description]
	 */
	function login($server){
		$conn = ssh2_connect($server['ip'],$server['port']); 
		$password=$this->input('server '.$server['user'].'@'.$server['ip'].' password:');
		$login=ssh2_auth_password($conn,$server['user'],$password);
		if(!$login){
			$continue=trim(strtoupper($this->input('unable connect to server '.$server['ip'].',connect (y/n)','yellow')));
			if($continue=='N'){
				$this->error(" close script\n");
			}else{
				$this->tip(" continue next step...\n");
			}
			$this->server=null;
			return false;	
		}
		$this->server=$server['ip'];
		return $conn;
	}


	public function log($cmd='all',$num=5){
		$this->putlog(false);
		$this->warning("log list:\n");
		$logs=[];
		foreach ($this->log as $log) {
			if($cmd=='all' || $cmd==$log['cmd']){
				array_push($logs, $log);
			}
		}
		$logs=array_slice($logs, 0-$num);

		print_r($logs);
	}



	function str_replace_once($needle, $replace, $haystack) {
		$pos = strpos($haystack, $needle);
		//var_dump($pos);
		if ($pos === false) {
			return $haystack;
		}
		//var_dump(substr_replace($haystack, $replace, $pos, strlen($needle)));
		return substr_replace($haystack, $replace, $pos, strlen($needle));
	}





	/**
	 * 执行系统命令
	 * @Author   Woldy
	 * @DateTime 2016-05-13T12:43:39+0800
	 * @param    [type]                   $str [description]
	 * @return   [type]                        [description]
	 */
	function shell($str,$check=true){
		if(!$check){
			$this->tip(shell_exec($str));
			return;
		}
		$tmppath=$this->config['local_tmp_path'];
		$resultPath=$this->config['local_tmp_path'].'/result';
		$clear=file_put_contents($resultPath,'');
		if(false===$clear){
			$this->error("cannot write $resultPath!\n");
		}
		shell_exec($str.' > "'.$resultPath.'"');
		$result=trim(file_get_contents($resultPath));
		if(!empty($result)){
			$this->warning($result."\n");
			$continue=trim(strtoupper($this->input("\n".'there has some warning,so .. continue? (y/n)')));
			if($continue=='N'){
				$this->error("close script");
			}else{
				$this->tip("continue next step...\n");
			}
		}
	}



	/**
	 * 写入日志
	 * @Author   Woldy
	 * @DateTime 2016-05-13T18:33:59+0800
	 * @param    [type]                   $cmd [description]
	 * @param    string                   $logPath [description]
	 * @return   [type]                            [description]
	 */
	function putlog($cmd='unknown',$logPath="codepush.log.php"){
		if(file_exists($logPath)){
			$this->log=require($logPath);
			$this->taskid=end($this->log)['taskid']+1;
			if(!$cmd){
				return;
			}
			array_push($this->log,[
				'taskid'=>$this->taskid,
				'time'=>date("Y-m-d H:i:s"),
				'cmd'=>$cmd,
				'config'=>json_encode($this->config)
			]);
		}else{
			$this->log=[
				[
					'taskid'=>'0',
					'time'=>date("Y-m-d H:i:s"),
					'cmd'=>$cmd,
					'config'=>json_encode($this->config)
				],
			];
		}

		$fp = fopen($logPath, 'wb+');
		fwrite($fp, "<?php\n return ".var_export($this->log, true).';');
		fclose($fp);
	}




	/**
	 * 询问是否继续
	 * @Author   Woldy
	 * @DateTime 2016-05-13T18:34:17+0800
	 * @param    [type]                   $tip [description]
	 */
	function YNgo($tip){
		$continue=trim(strtoupper($this->input($tip.',continue? (y/n)','yellow')));
		if($continue=='N'){
			$this->error(" close script\n");
		}else{
			$this->tip(" continue next step...\n");
		}
	}

	/**
	 * 输出警告
	 * @Author   Woldy
	 * @DateTime 2016-05-13T13:18:41+0800
	 * @param    [type]                   $str [description]
	 * @return   [type]                        [description]
	 */
	function warning($str){
		$this->output($str,'yellow');
	}

	/**
	 * 输出提示
	 * @Author   Woldy
	 * @DateTime 2016-05-13T13:18:56+0800
	 * @param    [type]                   $str [description]
	 * @return   [type]                        [description]
	 */
	function tip($str){
		$this->output($str,'white');
	}

	/**
	 * 输出成功
	 * @Author   Woldy
	 * @DateTime 2016-05-13T13:18:56+0800
	 * @param    [type]                   $str [description]
	 * @return   [type]                        [description]
	 */
	function success($str){
		$this->output($str,'green');
	}

	/**
	 * 输出错误并退出程序
	 * @Author   Woldy
	 * @DateTime 2016-05-13T13:18:56+0800
	 * @param    [type]                   $str [description]
	 * @return   [type]                        [description]
	 */
	function error($str,$exit=true){
		$this->output($str,'red');
		if($exit){
			exit();
		}
	}

	
	function output($text,$color=''){
		if(!empty($this->server)){
			$text="server[$this->server] ".$text;
		}
		if(!empty($this->taskid)){
			$text="task[$this->taskid] ".$text;
		}

		$color_list=array(
			'black'=>'30',
			'red'=>'31',
			'green'=>'32',
			'yellow'=>'33',
			'blue'=>'34',
			'purple'=>'35',
			'cyan'=>'36',
			'white'=>'37'
		);
		if(!empty($color) && array_key_exists ($color, $color_list)){
			echo "\033[0;".$color_list[$color]."m$text \x1B[0m";
		}else{
			echo $text;
		}
	}

	function input($tip='input:',$color='white'){
		$this->output($tip,$color);
        return rtrim(fgets(STDIN));
	}
}

$cmd=isset($argv[1])?$argv[1]:'';
$task=new CodePush();
$task->start($cmd);