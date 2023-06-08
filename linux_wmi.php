<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
$wmi = new Linux_WMI ();
$wmi->hostname = '192.168.126.1';
$wmi->username = 'test';
$wmi->password = 'test';
$wmi->querynspace = 'root\\CIMV2';
$wmi->command = "select * from Win32_Process";

$results = $wmi->fetch();
print_r($results);
*/

class Linux_WMI {
	var $hostid;      // ID of host, to pull authenication from
	var $hostname;    // Hostname / IP to contact
	var $username;    // Username to authenicate with (will be pulled from DB)
	var $password;    // Password to authenicate with (will be pulled form DB)
	var $command;     // This is the WMI Query to exec
	var $results;     // This will hold all our data
	var $binary;      // Path to WMIC binary
	var $separator;   // Separator (defaults to |)
	var $error;       // Last Error message
	var $indexkey;    // Key to use as Index
	var $keys;        // Comma separated keys
	var $queryclass;  // Class name to pull
	var $querynspace; // Namespace to pull

	function __construct($hostid = '') {
		// Function for initial create
		$this->binary    = '/usr/bin//wmic';
		$this->separator = '|+|';
		$this->error     = false;

		if ($hostid != '') {
			$this->hostid = $hostid;

			/* Ensure we have a username / password pair setup for this host */
			$this->retrieve_account();
		}
	}

	function __destruct() {
		return true;
	}

	function create_query() {
		$this->command = "SELECT ";

		if ($this->keys != '') {
			if ($this->indexkey != '') {
				$this->command .= $this->indexkey . ',';
			}

			$this->command .= $this->keys;
		} else	if ($this->indexkey != '') {
			$this->command .= $this->indexkey . ',';
		} else {
			$this->command = '';
			$this->error = 'ERROR: WMI Keys and Index is Empty!';

			return false;
		}

		$this->command .= ' FROM ';
		if ($this->queryclass != '') {
			$this->command .= $this->queryclass;
		} else {
			$this->command = '';
			$this->error = 'ERROR: WMI Query Class is empty!';

			return false;
		}
	}

	function fetch_key_index($name) {
		if (isset($this->results[1])) {
			foreach ($this->results[1] as $i => $a) {
				if ($a == $name) {
					return $i;
				}
			}

			$this->error = 'ERROR: Key not found';

			return false;
		}
		$this->error = 'ERROR: Empty Result!';

		return false;
	}

	function fetch_value($keyname, $index) {
		$i = $this->fetch_key_index($this->indexkey);
		$k = $this->fetch_key_index($keyname);
		$results = $this->results;
		if (isset($results[2])) {
			array_shift($results);
			array_shift($results);
			foreach ($results as $r) {
				if (str_replace(array(' ','(', ')'), '', $r[$i]) == $index) {
					return $r[$k];
				}
			}
		}

		return false;
	}

	function print_fetch_key_value_pair($keyname, $index) {
		$i = $this->fetch_key_index($this->indexkey);
		$k = $this->fetch_key_index($keyname);

		$results = $this->results;

		if (isset($results[2])) {
			array_shift($results);
			array_shift($results);
			foreach ($results as $r) {
				if (str_replace(array(' ','(', ')'), '', $r[$i]) == $index) {
					print "$keyname!" . $r[$k] . "'" . PHP_EOL;
				}
			}
		}
	}

	function print_indexes() {
		$k = $this->fetch_key_index($this->indexkey);
		$results = $this->results;
		if (isset($results[2])) {
			array_shift($results);
			array_shift($results);

			foreach ($results as $r) {
				/* Indexes should not have spaces in their name so we remove them */
				print str_replace(array(' ','(', ')'), '', $r[$k]) . '!' . str_replace(array(' ','(', ')'), '', $r[$k]) . PHP_EOL;
			}
		}
	}

	function fetch_indexes() {
		if (isset($this->results[1])) {
			return $this->results[1];
		}else{
			return false;
		}
	}

	function fetch_class() {
		if (sizeof($this->results)) {
			return $this->results[0][0];
		}else{
			return false;
		}
	}

	function fetch_data() {
		if (sizeof($this->results) > 2) {
			$new = $this->results;
			array_shift($new);
			array_shift($new);

			return $new;
		}else{
			return array();
		}
	}

	function fetch() {
		if ($this->command == '') {
			$this->error = 'ERROR: WMI Query is empty';
			return false;
		}

		$results = $this->exec();

		if ($results !== false) {
			foreach ($results as $id => $result) {
				$results[$id] = explode($this->separator, $result);
			}

			$this->results = $results;

			return $results;
		}else{
			return false;
		}
	}

	function getcommand() {
		if ($this->username == '' || $this->password == '') {
			$this->error = 'ERROR: Username or Password not set!';

			return false;
		}

		$this->clean();

		return $this->binary .
			' --delimiter=' . $this->separator .
			' --user=' . $this->username .
			' --password=' . $this->password .
			($this->querynspace != '' ? ' --namespace=' . $this->querynspace:'') .
			' //' . trim($this->hostname) .
			' ' . $this->command;
	}

	function exec() {
		$command = $this->getcommand();

		if ($command === false) {
			return false;
		}

		//$command .= ' --option="client_ntlmv2_auth"=Yes';

		$config['cacti_server_os'] = 'unix';

		$return_var   = 0;
		$return_array = array();

		exec($command, $return_array, $return_var);

		if ($return_var != 0) {
			$this->error = 'ERROR: ' . implode("<br>", $return_array);
			return false;
		}elseif (!sizeof($return_array)) {
			$this->error = 'ERROR: WMI Returned no Data';
			return false;
		}else{
			return $return_array;
		}
	}

	function clean() {
		$this->username  = cacti_escapeshellarg($this->username);
		$this->password  = cacti_escapeshellarg($this->password);
		$this->hostname  = trim($this->hostname);
		$this->binary    = cacti_escapeshellarg($this->binary);
		$this->command   = cacti_escapeshellarg($this->command);
	}

	function retrieve_account() {
		if ($this->hostid == '') {
			$this->error = 'ERROR: hostid is not set!';
			return false;
		}

		$info = db_fetch_row_prepared("SELECT pwa.*
			FROM wmi_user_accounts AS pwa
			INNER JOIN host AS h
			WHERE pwa.id = h.wmi_account
			AND h.id = ?",
			array($this->hostid));

		if (isset($info['username'])) {
			$this->username = $info['username'];
			$this->password = $this->decode($info['password']);
			return true;
		}

		$this->error = 'ERROR: WMI Authenication account not found!';

		return false;
	}

	function decode($info) {
		$info = base64_decode($info);
		$info = unserialize($info);
		$info = $info['password'];

		return $info;
	}

	function encode($info) {
		$a = array(rand(1,time()) => rand(1,time()),'password' => '', rand(1,time()) => rand(1,time()));
		$a['password'] = $info;
		$a = serialize($a);
		$a = base64_encode($a);

		return $a;
	}
}

