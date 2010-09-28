<?php
// pib by zachera
// http://github.com/zachera/pib
class pib {
	public $socket;
	public $regex = "/^(?:[:@]([^\\s]+) )?([^\\s]+)(?: ((?:[^:\\s][^\\s]* ?)*))?(?: ?:(.*))?$/";
	public $match;
	public $pibname = 'pib';
	public $admin = array (
		'userip' => array ( '127.0.0.1'   ),
		'nick'   => array ( 'adminacc'    ),
		'host'   => array ( 'example.com' )
	);
	public $bufferToAdmin = false;

	public function __construct()
	{
		$this->socket = stream_socket_client("tcp://irc.swiftirc.net:6667");
		$this->w("USER ".$this->pibname." 0 * :".$this->pibname);
		$this->w("NICK ".$this->pibname);
		$this->w("SETNAME ".$this->pibname);
		$this->w("MODE ".$this->pibname." +B-x");
		$this->w("PRIVMSG NickServ IDENTIFY password");
		$this->w("JOIN #steven2");
		$this->r();
	}

	public function __call($function,$args)
	{
		$this->out('Invalid func '.$function.' with args ('.implode(',',$args).')');
	}

	public function r()
	{
		while(($buffer = fread($this->socket, 1024)) !== false)
		{
			if($buffer)
			{
				if(preg_match('/\n/i',$buffer))
				{
					$strings = explode("\n",$buffer);
					foreach($strings as $val)
						$this->proc(trim($val));
				}
				else
				{
					$this->proc($buffer);
				}
			}
		}
	}

	public function sh($what,$where='')
	{
		if($where == '')
		{
			$where = ($this->stored_match['buffer']['target'] == $this->pibname ? $this->stored_match['nickname']['nick'] : $this->stored_match['buffer']['target']);
		}
		$this->w('PRIVMSG '.$where.' '.shell_exec($what));
	}

	public function write($what)
	{
		$this->w($what);
	}

	public function w($what)
	{
		if(strpos($what,"PONG :") === false)
		{
			$this->output_sent = true;
		}
		$explode = explode(' ',$what);
		if($explode[0] == "PRIVMSG" || $explode[0] == "NOTICE")
		{
			$where = $explode[1];
			$what = str_replace("\n","\r\n".$explode[0]." ".$where." ",trim($what));
		}
		echo date('\[h:i:s\]',time())."[O] ".$what."\n";
		if($this->socket)
		{
			fwrite($this->socket,$what."\r\n");
		}
	}

	public function out($what,$where='')
	{
		if($where == '')
		{
			$where = ($this->stored_match['buffer']['target'] == $this->pibname ? $this->stored_match['nickname']['nick'] : $this->stored_match['buffer']['target']);
		}
		$this->w('PRIVMSG '.$where.' '.$what);
	}

	public function proc($buffer)
	{
		$match = array();
		if(strlen($buffer) > 0)
		{
			$this->output_sent = false;
			echo date('\[h:i:s\]',time())."[I] ".$buffer."\n";
			if($this->bufferToAdmin === true)
			{
				$this->out($buffer,$this->adminuser);
			}
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
					{
						$$value = '';
					}
					if(isset($source))
					{
						$nickname = array();
						if(preg_match('/^(.+?)!(.+?)@(.+?)$/', $source, $match['nickname'])) // thanks Viper-7
						{
							$mode = '';
							if(substr($match['nickname'][1],0,1) == '@') $mode = '@';
							if(substr($match['nickname'][1],0,1) == '+') $mode = '+';
							if(substr($match['nickname'][1],0,1) == '%') $mode = '%';
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
				echo 'source => '.$source."\n";
				echo 'cmd    => '.$cmd."\n";
				echo 'target => '.$target."\n";
				echo 'param  => '.$param."\n";
				if(isset($nickname) && count($nickname) > 0)
				{
					// more debug
					echo 'nickname[nick]  => '.$nickname['nick']."\n";
					echo 'nickname[mode]  => '.$nickname['mode']."\n";
					echo 'nickname[ident] => '.$nickname['ident']."\n";
					echo 'nickname[host]  => '.$nickname['host']."\n";
				}
				switch($cmd)
				{
					case 'PING':
						$this->w('PONG :'.$param);
					break;
					case 'PRIVMSG':
						if(substr($param,0,1) == "~")
						{
							$this->stored_match = $match;
							$this->w('USERIP '.$nickname['nick']);
						}
						if($param == "\001VERSION\001")
						{
							$this->w('NOTICE '.$nickname['nick'].' :'."\001".'VERSION v0.1 http://github.com/zachera/pib'."\001");
						}
						if($param == "\001TIME\001")
						{
							$this->w('NOTICE '.$nickname['nick'].' :'."\001".date('Y-m-d G:i:s')."\001");
						}
					break;
					case 'ERROR':
						echo 'Disconnected! Reconnecting in 5 seconds...'."\n";
						sleep(5);
						$this->__construct();
					break;
					case 340:
						$userip = str_replace(array("*","~","-","+"),"",$param);
						if(preg_match("/(.+)\=(.+)@(.+)/i",$userip,$match['userip']))
						{
							list(,$nick,$ident,$userip) = $match['userip'];
							if(in_array($userip,$this->admin['userip']))
							{
								if(in_array($this->stored_match['nickname']['nick'],$this->admin['nick']))
								{
									if(in_array($this->stored_match['nickname']['host'],$this->admin['host']))
									{
										ob_start();
										eval(substr($this->stored_match['buffer']['param'],1));
										$buffered_output = ob_get_contents();
										ob_end_clean();
										if($this->output_sent === false)
											$this->out($buffered_output);
									}
									else
									{
										echo 'Access denied for '.$this->stored_match['nickname']['host']."\n";
									}
								}
								else
								{
									echo 'Access denied for '.$this->stored_match['nickname']['nick']."\n";
								}
							}
							else
							{
								echo 'Access denied for '.$userip."\n";
							}
						}
						$this->stored_match = array();
						$this->output_sent = false;
					break;
				}
			}
		}
	}
}
$pib = new pib();
?>
