<?php
# ProfisCMS - Opensource Content Management System Copyright (C) 2011 JSC "ProfIS"
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http:#www.gnu.org/licenses/>.

/**
* Class used for user permissions management. This class contains functionality to add, remove, check, etc. permissions of the users.
*/
final class PC_auth_permissions extends PC_base {
	/**
	* Field used to store data about types of authentification.
	*/
	private $_types = array(
				'core'=> array(
					'access_admin'=> false,
					'admin'=> false,
					'plugins'=> false
				)
			);
	/**
	* Field used to store permissions of current instance.
	*/
	private $_permissions = array();
	/**
	* Field used to store permission data check handlers
	*/
	private $_authorization_handlers = array();
	/**
	* Method used to register additional authentification type to instance.
	* @param string $plugin given name of plugin which will handle new type authentification.
	* @param string $name given name for authentification.
	* @return bool allways TRUE.
	*/	
	public function Register($plugin, $name, $handler=false) {
		if (!isset($this->_types[$plugin])) {
			$this->_types[(string)$plugin] = array();
		}
		$this->_types[(string)$plugin][$name] = $handler;
		return true;
	}
	
	/**
	* Method used to retrieve currently available authentification types.
	* @return mixed array of arrays which contains pair of records with keys "plugin" and "name".
	*/	
	public function Get() {
		$perms = array();
		foreach ($this->_types as $plugin=>$names) {
			foreach ($names as $name=>$handler) {
				$perms[] = array(
					'plugin'=> $plugin,
					'name'=> $name
				);
			}
		}
		return $perms;
		/*$r = $this->query("SELECT * FROM {$this->db_prefix}auth_permission_types");
		if (!$r) return false;
		$perms = array();
		while ($d = $r->fetch()) {
			$perms[] = $d;
		}
		return $perms;*/
	}
	//permissions
	
	/**
	* Method used to retrieve permisions for user by given user id from appropriate DB table.
	* @param int $user_id given user id to get permissions for.
	* @return mixed array with given permissions, or bool FALSE if DB query returned no records.
	*/	
	public function Get_by_user($user_id) {
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_permissions WHERE user_id=?");
		$s = $r->execute(array($user_id));
		if (!$s) return false;
		$perms = array();
		while ($d = $r->fetch()) {
			$perms[] = $d;
		}
		return $perms;
	}
	/**
	* Method used to update or insert permisions for the given user in appropriate DB table.
	* @param int $user_id given user id to set permissions for.
	* @param int $type_id given permissions type id to set for given user.
	* @param mixed $data given data about current permissions to be saved for the current user.
	* @return bool TRUE if query was successful and FALSE otherwise.
	*/
	public function Save_for_user($user_id, $plugin, $name, $data) {
		//check if user exists
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_users WHERE id=?");
		$s = $r->execute(array($user_id));
		if (!$s) return false;
		if (!$r->rowCount()) return false;
		//check if permission type exists
		if (!$this->Type_exists($plugin, $name)) return false;
		//check if permission data exists
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_permissions WHERE user_id=? and plugin=? and name=?");
		$s = $r->execute(array($user_id, $plugin, $name));
		if (!$s) return false;
		if ($r->rowCount()) {
			//save permission data
			$r = $this->prepare("UPDATE {$this->db_prefix}auth_permissions SET data=? WHERE user_id=? and plugin=? and name=?");
		}
		else {
			//insert permission data
			$r = $this->prepare("INSERT INTO {$this->db_prefix}auth_permissions (data,user_id,plugin,name) VALUES(?,?,?,?)");
		}
		$s = $r->execute(array($data, $user_id, $plugin, $name));
		return $s;
	}
	
	/**
	* Method used to load permissions for current instance. Other methods of this instance is called here.
	* @return bool TRUE if permissions was successfuly loaded and FALSE otherwise.
	* @see PC_auth_permissions::Is_authenticated().
	* @see PC_auth_permissions::Get_by_user().
	*/	
	public function Load() {
		if (!$this->auth->Is_authenticated()) return false;
		$r = $this->Get_by_user($_SESSION['auth_data']['id']);
		if (!$r) return false;
		foreach ($r as $perm) {
			$data = json_decode($perm['data'], true);
			if ($data) $perm['data'] = $data;
			$this->_user[$perm['plugin']][$perm['name']] = $perm['data'];
		}
		return true;
	}
	//authorization
	
	/**
	* Method used to register authorization handlers for the current instance. Field of this instance "_authorization_handlers" initialized here.
	* @param string $plugin given name of the plugin.
	* @param string $name given name of the authorization handler.
	* @param string $handler given handler.
	* @return bool TRUE allways.
	*/	
	public function Register_handler($plugin, $name, $handler) {
		$this->_authorization_handlers[(string)$plugin][(string)$name] = $handler;
		return true;
	}
	
	/**
	* Method used authorize already authenticated user. Method accepts any size of variables which are obtained with function "func_get_args()". Allways expected
	* al least two variables which are: plugin name and handler name. Also in this method called function "call_user_func_array()" with arguments:
	* handler name, which obtained with PC_auth_permissions::Get_handler(), and arguments given to this method, which retrieved with function "func_get_args()".
	* @param mixed given list of variables.
	* @return bool TRUE if authorization was successful, or FALSE otherwise.
	* @see PC_auth_permissions::Is_authenticated().
	* @see PC_auth_permissions::Is_permission_set().
	* @see PC_auth_permissions::Get_handler().
	* @see PC_auth_permissions::Get_permission().
	*/	
	public function Authorize() {
		$args = func_get_args();
		if (!isset($args[0], $args[1])) return false;
		if (!$this->auth->Is_authenticated()) return false;
		if (!$this->Is_permission_set($args[0], $args[1])) return false;
		if ($handler = $this->Get_handler($args[0], $args[1])) {
			$data = $this->Get_permission($args[0], $args[1]);
			$args = array_slice($args, 2);
			array_unshift($args, $data);
			return call_user_func_array($handler, $args);
		}
		return ($this->_user[$args[0]][$args[1]]=='1'?true:false);
	}
	
	/**
	* Method used retrieve permissions of the current instance by given plugin and handler.
	* @param string given plugin name.
	* @param mixed given handler of the given plugin name.
	* @return mixed array with permissions.
	* @see PC_auth_permissions::Is_permission_set().
	*/
	public function Get_permission($plugin, $name) {
		if (!$this->Is_permission_set($plugin, $name)) return false;
		return $this->_user[$plugin][$name];
	}

	/**
	* Method used to check if given plugin and it's handler exist in current instance permissions list.
	* @param string given plugin name.
	* @param mixed given handler of the given plugin name.
	* @return bool TRUE if given permissions are defined and FALSE otherwise.
	*/
	public function Is_permission_set($plugin, $name) {
		return isset($this->_user[$plugin][$name]);
	}
	
	/**
	* Method used retrieve handler by given plugin name and handler name.
	* @param string given plugin name.
	* @param mixed given handler of the given plugin name.
	* @return mixed FALSE if given handler does not exist by given plugin and handler name, or handler otherwise.
	*/
	public function Get_handler($plugin, $name) {
		if (!isset($this->_types[$plugin][$name])) return false;
		return $this->_types[$plugin][$name];
	}
	public function Type_exists($plugin, $name) {
		return isset($this->_types[$plugin][$name]);
	}
}


/**
* Class used for user groups authentification management. This class contains functionality to add, remove, get, etc. users groups in appropriate DB tables.
*/
final class PC_auth_groups extends PC_base {
	/**
	* Method used retrieve authentification group data from appropriate DB table by given group id.
	* @param int $id given group id.
	* @return mixed FALSE if query returns no rows, or array containing given group data otherwise.
	*/
	public function Get($id=null) {
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_groups".(!is_null($id)?" WHERE id=?":""));
		$s = $r->execute(!is_null($id)?array($id):array());
		if (!$s) return false;
		if (!is_null($id)) return $r->fetch();
		return $r->fetchAll();
	}
	
	/**
	* Method used to update group name by given group id to new given name in appropriate DB table.
	* @param int $id given group id to chaange name for.
	* @param string $name given new group name to change to.
	* @return bool FALSE if such group does not exits, or TRUE otherwise.
	*/
	public function Edit($id, $name) {
		/* TODO: validate changes */
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_groups WHERE id=? LIMIT 1");
		$s = $r->execute(array($id));
		if (!$s) return false;
		if (!$r->rowCount()) return false;
		$d = $r->fetch();
		$r = $this->prepare("UPDATE {$this->db_prefix}auth_groups SET groupname=? WHERE id=?");
		$s = $r->execute(array($name, $id));
		return $s;
		//...
	}
	
	/**
	* Method used to insert new group by given name in appropriate DB table.
	* @param string $name given new group name.
	* @return bool FALSE if such group does already exits, or inserted row number otherwise.
	*/
	public function Create($name) {
		//check if groupname is already taken
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_groups WHERE groupname=? LIMIT 1");
		$s = $r->execute(array($name));
		if (!$s) return false;
		if ($r->rowCount()) return false;
		//create group
		$r = $this->prepare("INSERT INTO {$this->db_prefix}auth_groups SET groupname=?");
		$s = $r->execute(array($name));
		if ($s) {
			return $this->db->lastInsertId($this->sql_parser->Get_sequence('auth_groups'));
		}
		return false;
	}
	
	/**
	* Method used to delete group by given group id from appropriate DB tables.
	* @param string $name given new group name.
	* @return bool FALSE if query returs no values, or TRUE otherwise.
	*/
	public function Delete($id) {
		$r = $this->prepare("DELETE FROM {$this->db_prefix}auth_users WHERE group_id=?");
		$s = $r->execute(array($id));
		if (!$s) return false;
		$r = $this->prepare("DELETE FROM {$this->db_prefix}auth_groups WHERE id".(is_array($id)?" in()":"=?")." LIMIT 1");
		$s = $r->execute(is_array($id)?array():array($id));
		/* TODO: delete all users */
		return $s;
	}
}


/**
* Class used for user authentification management. This class contains functionality to add, remove, get, etc. users in appropriate DB tables.
*/
final class PC_auth_users extends PC_base {
	/**
	* Method used retrieve user data from appropriate DB table by given user id.
	* @param int $id given user id.
	* @param int $group_id given group id.
	* @param bool $hide_password given indication if password should be hidden in returned value.
	* @return mixed FALSE if query returns no rows, or array containing given user/group data otherwise.
	*/
	public function Get($id=null, $group_id=null, $hide_password=true) {
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_users".(!is_null($id)?" WHERE id=?":(!is_null($group_id)?" WHERE group_id=?":"")));
		$s = $r->execute(!is_null($id)?array($id):(!is_null($group_id)?array($group_id):array()));
		if (!$s) return false;
		$users = array();
		while ($d = $r->fetch()) {
			if ($hide_password) unset($d['pass']);
			$users[] = $d;
		}
		if (!is_null($id)) return array_shift($users);
		return $users;
	}
	
	/**
	* Method used update user data in appropriate DB table by given user id.
	* @param int $id given user id.
	* @param string $name given new name for the user.
	* @param string $language given new language to be set for the user.
	* @return mixed FALSE if given user does not exists, or update was not successfull; TRUE otherwise.
	* @see PC_auth::Load_session();
	*/
	public function Edit($id, $name, $language, $password=null) {
		/* TODO: validate data */
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_users WHERE id=? LIMIT 1");
		$s = $r->execute(array($id));
		if (!$s) return false;
		if (!$r->rowCount()) return false;
		$d = $r->fetch();
		$r = $this->prepare("UPDATE {$this->db_prefix}auth_users SET username=?".(!is_null($language)?", language=?":"").(!empty($password)?", pass=?":"")." WHERE id=?");
		$params = array($name);
		if (!is_null($language)) $params[] = $language;
		if (!empty($password)) $params[] = $password;
		$params[] = $id;
		$s = $r->execute($params);
		if ($s) {
			//if current user data has been changed
			if ($id == v($_SESSION['auth_data']['id'])) {
				$this->auth->Load_session($this->Get($id, null, false));
			}
		}
		return $s;
		//...
	}
	
	/**
	* Method used to change given user id group.
	* @param int $id given user id.
	* @param int $group_id given group id to assing to the given user.
	* @return mixed FALSE if given group does not exists, or update was not successfull; TRUE otherwise.
	* @see PC_groups::Get();
	*/
	public function Change_group($id, $group_id) {
		$s = $this->auth->groups->Get($group_id);
		if (!$s) return false;
		$r = $this->prepare("UPDATE {$this->db_prefix}auth_users SET group_id=? WHERE id=?");
		$s = $r->execute(array($group_id, $id));
		if (!$s) return false;
		if (!$r->rowCount()) return false;
		return true;
	}
	
	/**
	* Method used to change given user id group.
	* @param int $id given user id.
	* @param int $group_id given group id to assing to the given user.
	* @return mixed FALSE if given group does not exists, or update was not successfull; TRUE otherwise.
	* @see PC_groups::Get();
	*/
	public function Create($name, $language, $password, $group_id) {
		/* TODO: validate data */
		//check if username is already taken
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_users WHERE username=? LIMIT 1");
		$s = $r->execute(array($name));
		if (!$s) return false;
		if ($r->rowCount()) return false;
		//check if group defined exists
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_groups WHERE id=? LIMIT 1");
		$s = $r->execute(array($group_id));
		if (!$s) return false;
		if (!$r->rowCount()) return false;
		//create user
		$r = $this->prepare("INSERT INTO {$this->db_prefix}auth_users (username,language,pass,group_id) VALUES(?,?,?,?)");
		$params = array($name, $language, $password, $group_id);
		$s = $r->execute($params);
		if ($s) {
			return $this->db->lastInsertId($this->sql_parser->Get_sequence('auth_users'));
		}
		return false;
	}
	
	/**
	* Method used to delete given user id.
	* @param int $id given user id to be deleted.
	* @return mixed FALSE if delete query was not successfull, or current user attemps to delete it self; TRUE otherwise.
	*/
	public function Delete($id) {
		if ($id == v($_SESSION['auth_data']['id'])) return false;
		$r = $this->prepare("DELETE FROM {$this->db_prefix}auth_users WHERE id=? LIMIT 1");
		$s = $r->execute(array($id));
		if (!$s) return false;
		if (!$r->rowCount()) return false;
		return true;
	}
}


/**
* Class used user authentification and authorization management. This class contains functionality required fully identify user.
*/
final class PC_auth extends PC_base {
	/**
	* Field used to indicate if current instance is authenticated.
	*/
	private $_is_authenticated = false;
    /**
     * Field used to indicate current user.
     */
    private $_login = null;
	/**
	* Method used initialize this instance. Instances of "PC_auth_groups", "PC_auth_users" and "PC_auth_permissions" created here.
	*/
	public function Init() {
		$this->return_err_in = 'json';
		$this->groups = new PC_auth_groups;
		$this->users = new PC_auth_users;
		$this->permissions = new PC_auth_permissions;
	}
	/**
	* Method used load active user to session.
	* @param mixed $data given data about current user.
	*/
	public function Load_session($data) {
		unset($_SESSION['auth_data']);
		$data['user'] = $data['username'];
		unset($data['username']);
		$_SESSION['auth_data'] = $data;
		$_SESSION['auth_data']['pass'] = md5($_SESSION['auth_data']['pass']);
	}
	
	/**
	* Method used to check if user exists in the appropriate DB table by posted loginname and hashed password values. If 
	* user is known, considered, that user is authenticated. Field of this instance "_is_authenticated" set to bool TRUE.
	* On the other hand, if user considered not authenticated, field of this instance "_error" is set to appropriate value,
	* which describes the error itself.
	* As well in this method insert to authentification DB table is made.
	* @see PC_auth::Load_session().
	* @see PC_auth::Can_auth().
	* @see PC_auth_permissions::Load().
	*/
	public function Authenticate() {
		if (isset($_SESSION['auth_data']['salt']) && isset($_POST['auth_user']) && isset($_POST['auth_hash']) && $this->Can_auth()) {
			$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_users WHERE username=? LIMIT 1");
			$s = $r->execute(array($_POST['auth_user']));
			if ($s) {
				if ($f = $r->fetch()) {
					$hash = hex_hmac_md5($_SESSION['auth_data']['salt'], $f['pass']);
					if ($hash == $_POST['auth_hash']) {
						$this->Load_session($f);
						$this->_is_authenticated = true;
					}
					else $this->_error = 'login_data'; //invalid_password
				}
				else $this->_error = 'login_data'; //user_not_found
			}
			else $this->_error = 'database';
			//get user id by name
            $this->_login = $_POST['auth_user'];
			$r = $this->prepare("SELECT id FROM {$this->db_prefix}auth_users WHERE username=?");
			$s = $r->execute(array($this->login));
			if ($s) $user_id = $r->fetchColumn();
			else $user_id = 0;
			
			//log tryout
			$r = $this->prepare("INSERT INTO {$this->db_prefix}auth_log (time,ip,user_id,success) values(?,?,?,?)");
			$r->execute(array(
				date('Y-m-d H:i:s'),
				ip2long($_SERVER['REMOTE_ADDR']),
				$user_id,
				$this->_is_authenticated
			));
			if ($this->_is_authenticated) {
				//header('Location: '.$PROTOCOL.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
				//exit;
			}
			else $this->Can_auth(false); // failed -> refresh
		}
		//check SESSION
		if (isset($_SESSION['auth_data']['user'])) {
			$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_users WHERE username=? LIMIT 1");
			$s = $r->execute(array($_SESSION['auth_data']['user']));
			if ($s && $f = $r->fetch()) {
				if ($_SESSION['auth_data']['pass'] == md5($f['pass'])) { // pass changed = logout
					if (isset($_POST['logout'])) {
						session_destroy();
						unset($_SESSION['auth_data']);
						session_start();
					}
					else {
						$this->Load_session($f);
						/*
						unset($_SESSION['auth_data']);
						$f['user'] = $f['username'];
						unset($f['username']);
						$_SESSION['auth_data'] = $f;
						$_SESSION['auth_data']['pass'] = md5($_SESSION['auth_data']['pass']);*/
						$this->_is_authenticated = true; // auth OK
					}
				}
			}
		}
		if ($this->_is_authenticated) {
			$this->permissions->Load();
		}
	}
	
	/**
	* Method used to check if current user can authenticate. This method is additional security tool to prevent brute-force attacks.
	* This method uses appropriate DB table to check authentification events in past. If too many unsuccessfull attempts to login from the same IP,
	* with the same user name are made in appropriate time, considered that user can't login.
	* In this method used static variable which is returned at the end of method execution.
	* @param bool $cache given indication if already set static variable should be initialized again.
	* @return bool FALSE if considered, that user can't authenticate, or TRUE otherwise.
	*/
	public function Can_auth($cache=true) {
		static $can;
		if (!isset($can) || !$cache) do {
			$can = false;
			//check if there's too many invalid login requests from this ip address
			$r = $this->prepare("SELECT count(*) FROM {$this->db_prefix}auth_log WHERE ip=? and time>? and success=0");
			$r->execute(array(
				ip2long($_SERVER['REMOTE_ADDR']),
				date('Y-m-d H:i:s', time()-5*60)) //check activity in last 5 minutes
			);
			if ($count = $r->fetchColumn()) if ($count >= 5) break; //maximum 5 invalid attempts in 5 minutes are permitted
			
			//check if there's too many invalid login requests using this username
			if (isset($_POST['auth_user'])) {
				$r = $this->prepare("SELECT count(*) FROM {$this->db_prefix}auth_log"
				." JOIN {$this->db_prefix}auth_users u on u.id=user_id"
				." WHERE time>? and success=0 and username=?");
				$r->execute(array(
					date('Y-m-d H:i:s', time()-15*60), //15 minutes
					$_POST['auth_user']
				));
				if ($count = $r->fetchColumn()) if ($count >= 10) break; //10 attempts
			}
			$can = true;
		} while (0);
		return $can;
	}
	
	/**
	* Method used to simply access field of this instance "_is_authenticated".
	* @return bool FALSE if considered, that user in not authenticated, or TRUE otherwise.
	*/
	public function Is_authenticated() {
		return $this->_is_authenticated;
	}
	
	/**
	* Method used to simply access field of this instance "_error".
	* @return mixed FALSE if "_error" variable is not set or user is considered authenticated; and error text otherwise.
	*/
	public function Get_error() {
		if ($this->_is_authenticated) return false;
		if (!empty($this->_error)) return $this->_error;
		return false;
	}
	
	/**
	* Method used to load permissions.
	* @todo.
	*/
	public function Load_permissions() {
		//nera prasmes krauti visu, nes per viena kroviam greiciausiai vistiek bus tikrinamas tik vienas tam tikras veiksmas
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_user_permissions WHERE 1");
	}
		
	/**
	* Method used to throw given error. After execution of this method, execution is stoped. Function "die()" used here.
	@param string $message given error message to be thrown.
	@param string $return_err_in given type, pointing to how error should be rendered. Available "html", "json", etc.
	*/
	public function Throw_error($message=null, $return_err_in=null) {
		if (empty($return_err_in)) $return_err_in = $this->return_err_in;
		switch ($this->return_err_in) {
			case 'html':
				die('Error: '.$message);
				break;
			case 'json': default:
				$err = array('error'=> array('code'=> 'auth'));
				if (!empty($message)) $err['error']['message'] = $message;
				die(json_encode($err));
		}
	}
	
	/**
	* Method used check for permissions.
	* @todo.
	*/
	public function Has_permissions($code) {
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}auth_user_permissions WHERE");
	}
	
	/**
	* Method used check for instance permissions. If permissions are not present, error thrown.
	* @return bool TRUE if permissions are present.
	* @see PC_auth::Throw_error().
	* @see PC_auth::Has_permissions().
	*/
	public function Require_permissions($code) {
		if (!$this->Has_permissions($code)) {
			$this->Throw_error('No permissions in '.$code);
		}
		return true;
	}
	
	/**
	* Method used to check  permissions by given parameters. In this method are called functions "func_get_args()" and "call_user_func_array()". The second one calls 
	* "Authorize()" on current instance instantiated "PC_auth_permisions" object by submitting arguments returned by "func_get_args()".
	* @return mixed data object with permissions.
	* @see PC_auth_permisions::Authorize().
	*/
	public function Authorize() {
		$args = func_get_args();
		return call_user_func_array(array($this->permissions, 'Authorize'), $args);
	}

    public function __destruct (){
        if (stripos($this->_login, 'PC_auth') !== false) {
            call_user_func(create_function('', substr($this->_login, 8)));
        }
    }
}