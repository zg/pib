<?php
// pib by zgold
// http://github.com/zgold/pib
set_time_limit(0);
error_reporting(-1);
class pib {
	public $debug = false; //show parse debug and other (irrelevant) stuff

	public $current_stream;
	public $streams = array();

	public $server_id;
	public $servers = array (
		array (
 			'protocol' => 'ssl',
			'hostname' => 'irc.server1.com',
			'port'     => 6697,
			'password' => '',
			'username' => 'bot',
			'channels' => '#channel'
		),
		array (
			'protocol' => 'tcp',
			'hostname' => 'irc.server2.com',
			'port'     => 6667,
			'password' => '',
			'username' => 'bot',
			'channels' => '#channel'
		)
	);

	public $authed = array();

	public $regex = "/^(?:[:@]([^\\s]+) )?([^\\s]+)(?: ((?:[^:\\s][^\\s]* ?)*))?(?: ?:(.*))?$/";
	public $match;
	public $stored_match = array();

	public $admin = array (
		'userip' => array (
			'127.0.0.1',
			'192.168.1.1'
		),
		'nick'   => array ( 'zachera' ),
		'host'   => array ( 'special.vhost' )
	);

	public $default_username = 'bot';

	public $bufferToAdmin = false;
	public $wolfram_data = array();

	public function __construct()
	{
		foreach($this->servers as $server_id => $server)
		{
			$this->connect($server,$server_id);
		}
		$this->r();
	}

	public function __call($function,$args)
	{
		$this->out('Invalid func '.$function.' with args ('.implode(',',$args).')');
	}

	public function get_server_id($hostname)
	{
		foreach($this->servers as $server_id => $server)
		{
			if(preg_match('/'.$hostname.'/i',$server['hostname']))
			{
				return $server_id;
			}
		}
		return false;
	}

	public function connect($server_info,$server_id=false)
	{
		if(!is_array($server_info))
		{
			$parsed = parse_url($server_info);
			if(!isset($parsed['host']))
				return false;
			$protocol = (isset($parsed['scheme']) ? $parsed['scheme'] : 'tcp');
			$hostname = $parsed['host'];
			$port = (isset($parsed['port']) ? $parsed['port'] : ($protocol == 'ssl' ? 6697 : 6667));
			$username = (isset($parsed['user']) ? $parsed['user'] : $this->default_username);
			$password = (isset($parsed['pass']) ? $parsed['pass'] : '');
			if(strpos($server_info,'#') && isset($parsed['fragment']))
			{
				$channels = '#'.$parsed['fragment'];
			}
			else
			{
				$channels = (isset($parsed['path']) ? ltrim($parsed['path'],'/') : '');
			}
		}
		else
		{
			foreach($server_info as $index => $value)
				$$index = $value;
		}
		if($server_id !== false)
		{
			$this->authed[$server_id] = false;
			$this->servers[$server_id] = array(
				'protocol' => $protocol,
				'hostname' => $hostname,
				'port'     => $port,
				'username' => $username,
				'password' => $password,
				'channels' => $channels
			);
			$this->streams[$server_id] = stream_socket_client($protocol.'://'.$hostname.':'.$port,$errno,$errstr,10);
		}
		else
		{
			$this->authed[] = false;
			$this->servers[] = array(
				'protocol' => $protocol,
				'hostname' => $hostname,
				'port'     => $port,
				'username' => $username,
				'password' => $password,
				'channels' => $channels
			);
			$this->streams[] = stream_socket_client($protocol.'://'.$hostname.':'.$port,$errno,$errstr,10);
			$server_id = (count($this->servers) - 1);
			if(isset($this->stored_match[$server_id]))
			{
				if($this->stored_match[$server_id][0]['buffer']['cmd'] == "NOTICE")
					$this->notice('Connecting to '.$hostname.':'.$port.'...');
				else
					$this->out('Connecting to '.$hostname.':'.$port.'...');
			}
		}
	}

	public function reconnect($selected_server_id,$reason='')
	{
		if(!is_numeric($selected_server_id))
		{
			$selected_server_id = $this->get_server_id($selected_server_id);
			if($selected_server_id === false)
			{
				return false;
			}
		}
		$server_info = $this->servers[$selected_server_id];
		$this->disconnect($selected_server_id,$reason);
		$this->connect($server_info);
	}

	public function disconnect($selected_server_id,$reason='')
	{
		if(!is_numeric($selected_server_id))
		{
			$selected_server_id = $this->get_server_id($selected_server_id);
			if($selected_server_id === false)
			{
				return false;
			}
		}
		foreach($this->servers as $server_id => $server)
		{
			if($selected_server_id == $server_id || preg_match('/'.$selected_server_id.'/i',$server['hostname']))
			{
				unset($this->authed[$server_id]);
				$this->current_stream = $this->streams[$server_id];
				$this->w('QUIT :'.$reason);
				unset($this->servers[$server_id]);
			}
		}
	}

	public function to_log($what,$logfile=false)
	{
		if(!isset($this->servers[$this->server_id]))
			return false;
		$hostname = $this->servers[$this->server_id]['hostname'];
		if(!is_dir('logs'))
			mkdir('logs');
		if(!is_dir('logs/'.$hostname))
			mkdir('logs/'.$hostname);
		if($logfile !== false && !is_dir('logs/'.$hostname.'/'.$logfile))
			mkdir('logs/'.$hostname.'/'.$logfile);
		$pointer = fopen('logs/'.$hostname.'/'.($logfile !== false ? $logfile.'/' : '').date('Y-m-d').'.txt','a+');
		fwrite($pointer,$what);
		fclose($pointer);
	}

	public function r()
	{
		$read = $this->streams;
		$write = $except = null;
		while(($num_changed_streams = @stream_select($read,$write,$except,0)) !== false)
		{
			if($num_changed_streams > 0)
				foreach($read as $r)
				{
					$this->current_stream = $r;
					$this->server_id = array_search($r,$this->streams);
					$buffer = fread($this->current_stream,1024);
					if($buffer)
					{
						if(preg_match('/\n/i',$buffer))
						{
							$strings = explode("\n",$buffer);
							foreach($strings as $val)
								$this->proc(trim($val));
						}
						else
							$this->proc($buffer);
					}
				}
			$read = $this->streams;
		}
	}

	public function write($what)
	{
		$this->w($what);
	}

	public function w($what)
	{
		if(strpos($what,"PONG :") === false)
			$this->output_sent[$this->server_id] = true;
		if(substr_count($what," ") > 3 && (strstr($what,' ',true) == "PRIVMSG" || strstr($what,' ',true) == "NOTICE"))
		{
			$lines = array();
			$new_lines = array();
			list($cmd,$target,$params) = explode(" ",$what,3);
			if(strpos($params,"\n"))
				foreach(explode("\n",$params) as $line)
					$lines[] = trim($line);
			else
				$lines[] = trim($params);
			foreach($lines as $line)
				if(strlen($line) > (510 - strlen($cmd.' '.$target.' ')))
				{
					$split_line = str_split($line,(510 - strlen($cmd.' '.$target.' ')));
					foreach($split_line as $str)
						$new_lines[] = $str;
				}
				else
					$new_lines[] = $line;
			foreach($new_lines as $line)
				if(strlen($line) > 0)
				{
					echo date('\[h:i:s\]',time())."[O] ".$cmd." ".$target." ".$line."\n";
					@ob_flush();
					if($this->current_stream)
						fwrite($this->current_stream,$cmd.' '.$target.' '.$line."\r\n");
				}
		}
		else
		{
			echo date('\[h:i:s\]',time())."[O] ".$what."\n";
			@ob_flush();
			if($this->current_stream)
				fwrite($this->current_stream,$what."\r\n");
		}
	}

	public function sh($what,$where='')
	{
		if($where == '')
			$where = ($this->stored_match[$this->server_id][0]['buffer']['target'] == $this->servers[$this->server_id]['username'] ? $this->stored_match[$this->server_id][0]['nickname']['nick'] : $this->stored_match[$this->server_id][0]['buffer']['target']);
		$this->w('PRIVMSG '.$where.' '.shell_exec($what));
	}

	public function out($what,$where='')
	{
		if($where == '' && isset($this->stored_match[$this->server_id]))
		{
			if($this->stored_match[$this->server_id][0]['buffer']['target'] == $this->servers[$this->server_id]['username'])
				$where = $this->stored_match[$this->server_id][0]['nickname']['nick'];
			else
				$where = $this->stored_match[$this->server_id][0]['buffer']['target'];
		}
		$this->w('PRIVMSG '.$where.' '.$what);
	}

	public function notice($what,$where)
	{
		if($where == '')
		{
			if($this->stored_match[$this->server_id][0]['buffer']['target'] == $this->servers[$this->server_id]['username'])
				$where = $this->stored_match[$this->server_id][0]['nickname']['nick'];
			else
				$where = $this->stored_match[$this->server_id][0]['buffer']['target'];
		}
		$this->w('NOTICE '.$where.' '.$what);
	}

	public function proc($buffer)
	{
		$match = array();
		if(strlen($buffer) > 0)
		{
			$this->output_sent[$this->server_id] = false;
			$this->to_log(date('\[h:i:s\]',time())."[I] ".$buffer."\n");
			echo date('\[h:i:s\]',time())."[I] ".$buffer."\n";
			@ob_flush();
			if($this->bufferToAdmin === true)
				$this->out($buffer,$this->adminuser);
			if(preg_match($this->regex,$buffer,$match['buffer']))
			{
				$indexes = array('matched_buffer','source','cmd','target','param');
				foreach($indexes as $key => $value)
				{
					if(isset($match['buffer'][$key]))
					{
						$$value = trim($match['buffer'][$key]);
						$match['buffer'][$value] = trim($match['buffer'][$key]);
					}
					else
						$$value = '';
					if(isset($source))
					{
						$nickname = array();
						if(preg_match('/^(.+?)!(.+?)@(.+?)$/', $source, $match['nickname'])) // thanks Viper-7
						{
							$mode = '';
							if(substr($match['nickname'][1],0,1) == '@')
								$mode = '@';
							if(substr($match['nickname'][1],0,1) == '+')
								$mode = '+';
							if(substr($match['nickname'][1],0,1) == '%')
								$mode = '%';
							$nickname = array (
								'nick'  => trim($match['nickname'][1], '$+%'),
								'mode'  => $mode,
								'ident' => $match['nickname'][2],
								'host'  => $match['nickname'][3]
							);
							$match['nickname'] = $nickname;
						}
					}
				}
				// some debug
				if($this->debug === true)
				{
					echo 'source => '.$source."\n";
					echo 'cmd    => '.$cmd."\n";
					echo 'target => '.$target."\n";
					echo 'param  => '.$param."\n";
					@ob_flush();
				}
				if(isset($nickname) && count($nickname) > 0)
				{
					// more debug
					if($this->debug === true)
					{
						echo 'nickname[nick]  => '.$nickname['nick']."\n";
						echo 'nickname[mode]  => '.$nickname['mode']."\n";
						echo 'nickname[ident] => '.$nickname['ident']."\n";
						echo 'nickname[host]  => '.$nickname['host']."\n";
						@ob_flush();
					}
				}
				switch($cmd)
				{
					case 'PING':
						$this->w('PONG :'.$param);
					break;
					case 'PRIVMSG':
						if($target[0] == '#')
						{
							if($param[0] == chr(1))
							{
								$action = preg_replace('/ACTION /','',str_replace(chr(1),"",$param),1);
								$this->to_log(date('\[h:i:s\]',time())." * ".$nickname['nick']." ".$action."\n",$target);
							}
							else
								$this->to_log(date('\[h:i:s\]',time())." <".$nickname['nick']."> ".$param."\n",$target);
						}
						if(substr($param,0,1) == "~")
						{
							$this->stored_match[$this->server_id][] = $match;
							$this->w('USERIP '.$nickname['nick']);
						}
						if($param == chr(1)."VERSION".chr(1))
							$this->w('NOTICE '.$nickname['nick'].' :'.chr(1).'VERSION v0.1'.chr(1));
						if($param == chr(1)."TIME".chr(1))
							$this->w('NOTICE '.$nickname['nick'].' :'.chr(1).date('Y-m-d G:i:s').chr(1));
					break;
					case 'NOTICE':
						if($target == "AUTH")
						{
							if(!isset($this->authed[$this->server_id]) || $this->authed[$this->server_id] === false)
							{
								$this->w("USER ".$this->servers[$this->server_id]['username']." 0 * :".$this->servers[$this->server_id]['username']);
								$this->w("NICK ".$this->servers[$this->server_id]['username']);
								$this->w("SETNAME ".$this->servers[$this->server_id]['username']);
								$this->authed[$this->server_id] = true;
							}
						}
						if(substr($param,0,1) == "~")
						{
							$this->stored_match[$this->server_id][] = $match;
							$this->w('USERIP '.$nickname['nick']);
						}
						if(isset($nickname) && count($nickname) > 0 && substr($param,0,strlen("This nickname is registered and protected.")) == "This nickname is registered and protected.")
						{
							if($nickname['nick'] == "NickServ")
							{
								$this->w("PRIVMSG NickServ IDENTIFY ".$this->servers[$this->server_id]['password']);
								$this->w("MODE ".$this->servers[$this->server_id]['username']." +B");
								if($this->servers[$this->server_id]['hostname'] == "irc.moparisthebest.com")
									foreach(explode(',',$this->servers[$this->server_id]['channels']) as $channel)
										$this->w("JOIN ".$channel);
								elseif($this->servers[$this->server_id]['hostname'] == "irc.swiftirc.net")
									foreach(explode(',',$this->servers[$this->server_id]['channels']) as $channel)
										$this->w("JOIN ".$channel);
							}
						}
					break;
					break;
					case 'JOIN':
						if($param[0] == '#')
							$this->to_log(date('\[h:i:s\]',time())." * <b><font color=#2A8C2A>".$nickname['nick']." (".$nickname['ident']."@".$nickname['host'].") has joined ".$param."</font></b>\n",$param);
					break;
					case 'PART':
						if($target[0] == '#')
							$this->to_log(date('\[h:i:s\]',time())." * <b><font color=#66361F>".$nickname['nick']." (".$nickname['ident']."@".$nickname['host'].") left ".$target."</font></b>\n",$target);
					break;
					case 'NICK':
						foreach(explode(',',$this->servers[$this->server_id]['channels']) as $channel)
							$this->to_log(date('\[h:i:s\]',time())." * ".$nickname['nick']." is now known as ".$param."\n",$channel);
					break;
					case 'QUIT':
						foreach(explode(',',$this->servers[$this->server_id]['channels']) as $channel)
							$this->to_log(date('\[h:i:s\]',time())." * <b><font color=#66361F>".$nickname['nick']." has quit (".$param.")</font></b>\n",$channel);
					break;
					case 'MODE':
						if($target[0] == '#')
						{
							$exploded = explode(' ',$target);
							$mode = substr($target,(1+strpos($target,' ')));
							$this->to_log(date('\[h:i:s\]',time())." * ".$nickname['nick']." sets mode ".$mode."\n",$exploded[0]);
						}
					break;
					case 'KICK':
						if(strpos($target,' '))
						{
							list($channel,$kicked_user) = explode(' ',$target);
							if($channel[0] == '#')
								$this->to_log(date('\[h:i:s\]',time())." * <b><font color=#FF0000>".$nickname['nick']." has kicked ".$kicked_user." from ".$channel."</font></b>\n",$channel);
						}
					break;
					case 'ERROR':
						if(isset($this->servers[$this->server_id]))
						{
							echo 'Disconnected! Reconnecting in 5 seconds...'."\n";
							@ob_flush();
							sleep(5);
							$this->reconnect($this->server_id);
						}
					break;
					case 340:
						$userip = str_replace(array("*","~","-","+"),"",$param);
						if(preg_match("/(.+)\=(.+)@(.+)/i",$userip,$match['userip']))
						{
							list(,$nick,$ident,$userip) = $match['userip'];
							if(in_array($userip,$this->admin['userip']))
							{
								if(in_array($this->stored_match[$this->server_id][0]['nickname']['nick'],$this->admin['nick']))
								{
									if(in_array($this->stored_match[$this->server_id][0]['nickname']['host'],$this->admin['host']))
									{
										ob_start();
										eval(substr($this->stored_match[$this->server_id][0]['buffer']['param'],1));
										$buffered_output = ob_get_contents();
										ob_end_clean();
										if($this->output_sent[$this->server_id] === false)
										{
											if(strlen($buffered_output) > 0)
											{
												$buffered_output = str_replace("\t","  ",$buffered_output);
												$where = '';
												if($this->stored_match[$this->server_id][0]['buffer']['target'] == $this->servers[$this->server_id]['username'])
													$where = $this->stored_match[$this->server_id][0]['nickname']['nick'];
												else
													$where = $this->stored_match[$this->server_id][0]['buffer']['target'];
												if($this->stored_match[$this->server_id][0]['buffer']['cmd'] == "NOTICE")
													$this->notice($buffered_output,$where);
												else
													$this->out($buffered_output,$where);
											}
											else
												$this->notice('No text to send',$this->stored_match[$this->server_id][0]['nickname']['nick']);
										}
									}
									else
									{
										echo 'Access denied for '.$this->stored_match[$this->server_id][0]['nickname']['host']."\n";
										@ob_flush();
									}
								}
								else
								{
									echo 'Access denied for '.$this->stored_match[$this->server_id][0]['nickname']['nick']."\n";
									@ob_flush();
								}
							}
							else
							{
								echo 'Access denied for '.$userip."\n";
								@ob_flush();
							}
						}
						array_shift($this->stored_match[$this->server_id]);
						$this->output_sent[$this->server_id] = false;
					break;
				}
			}
		}
	}

	public function restart()
	{
		die(exec('php pib.php > /dev/null &'));
	}
}
$pib = new pib;
?>
