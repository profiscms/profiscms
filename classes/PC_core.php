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
 * Class which performs a number of the functions. A class stands as a core website class specifically.
 */
final class PC_core extends PC_base {
	/**
	 * Private field $instances of type array.
	 */
	private $instances = array();
	/**
	 * Private fields $_hooks and $_callbacks of type array.
	 */
	private $_hooks = array(), $_callbacks = array();
	/**
	 * Public field $editor.
	 */
	public $editor;
	/**
	 * Method used to initialize configuration. 
	 */
	public function Init() {
		$this->Load_config();
	}
	/**
	 * Method for retrieving current version of CMS.
	 * @return string version of the ProfIS CMS.
	 */
	public function Get_version() {
		return PC_VERSION;
	}
	/**
	* Method used to obtain the path of the template. The path returned may differ depending on runtime circumstances.
	* @param mixed $theme given template name or left blank(not given at all) as default.
	* @param bool $public given indication of type of the template.
	* @return mixed FALSE if no template name given and site is not loaded yet; and template path otherwise.
	*/
	public function Get_theme_path($theme=null, $public=true) {
		if (is_null($theme)) {
			if (!$this->site->Is_loaded()) {
				return false;
			}
			$theme = $this->site->data['theme'];
		}
		if ($public) $path = 'themes/';
		else $path = $this->cfg['path']['themes'];
		return $path.$theme.'/';
	}
	/**
	* Method used to redirect user to error page.
	* @param mixed $err given error code to function to cope with. By error code function may display error page.
	*/
	public function Show_error($err) {
		/*if ($err == 404) {
			#show custom themes' 404 page
			if (is_file($theme_path.'/404.php')) {
				#require it
			}
			else {
				require('pluginss/core/pages/404.php');
			}
		}*/
		$this->site->render = false;
		#echo 'Error: <b>',$err,'</b>';
		$this->Redirect($this->cfg['url']['base']);
	}
	/**
	* Method used to redirect user to appropriate location by given parameters. Method flow allways ends with Redirect() method.
	* @see PC_core::Redirect().
	* @param string $action given action name by which function will redirect to location defined in $data variable, if name is 'http_redirect'.
	* @param mixed $data; arrays or ints are valid. In case of array $data['url'] or $data['page']['redirect'] is defined; in case of int HTTP code.
	*/
	public function Do_action($action, $data) {
		if ($action == 'http_redirect') {
			$this->Redirect((isset($data['url'])?$data['url']:$data['page']['redirect']));
		}
		elseif ($data == 404) {
			$this->site->render = false;
			#echo 'Error: <b>page not found</b>';
			#header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
			$this->Redirect($this->cfg['url']['base']);
		}
	}
	/**
	* Method used to redirect user to given location with "header()" and perform session top up with "session_write_close()". Flow 
	* allways stoped with "exit()" at * this function.
	* @param string $location given URI where user will be redirected.
	*/
	public function Redirect($location, $type=null) {
		switch ($type) {
			case 301: header("HTTP/1.1 301 Moved Permanently"); break;
			case 403: header("HTTP/1.1 403 Forbidden"); break;
			case 404: header("HTTP/1.1 404 Not Found"); break;
		}
		session_write_close();
		header("Location: ".$location);
		exit;
	}
	#instances - in development - not available yet
	#example: $this->Get('PC_class_news', '%plugin_dir%/PC_class_news.php');
	/*public function &Get($class, $path=null, $keyword=null, $reload=true) {
		$keyword = (string)$keyword;
		if (empty($keyword)) $keyword = 'default';
		if ($reload && isset($this->instances[$class][$keyword])) {
			return $this->instances[$class][$keyword];
		}
		#if (is_null($path)) $path = $this->path['classes']
	}*/
	
	#configuration
	/**
	* Method used to obtain configuration from database and to add it to $cfg variable for quick in-memory processing.
	* This method loads plugins configuration.
	*/
	public function Load_config() {
		#load config from database
		$r = $this->query("SELECT plugin,ckey,value FROM {$this->db_prefix}config");
		if (!$r) pc_die('Couldn\'t read config from the database');
		foreach ($r->fetchAll() as $a) {
			if (!empty($a['plugin'])) {
				#plugin specific parameters
				$this->cfg[$a['plugin']][$a['ckey']] = $a['value'];
			}
			else {
				#extract installed plugins list
				if ($a['ckey'] == 'active_plugins') {
					if (!empty($a['value'])) {
						$m = explode(',', $a['value']);
					}
					else $m = array();
					$this->cfg[$a['ckey']] = $m;
					unset($m);
				}
				#other parameters
				else $this->cfg[$a['ckey']] = $a['value'];
			}
		}
	}
	
	/**
	* Method used to update existing plugin or insert new one in database.
	* @param string $key given plugin unique key.
	* @param string $value given plugin value to be stored in database.
	* @param string $plugin given plugin name; null by default.
	* @return bool TRUE if UPDATE or INSERT succeeds; and FALSE if UPDATE or INSERT fails.
	*/
	public function Set_config($key, $value, $plugin=null) {
		#prepare query params
		$params = array(
			'value'=> $value, 
			'ckey'=> $key
		);
		if (!empty($plugin)) $params['plugin'] = $plugin;
		#try to update existing record
		$r = $this->prepare("UPDATE {$this->db_prefix}config SET value=:value WHERE ckey=:ckey".(!empty($plugin)?' and plugin=:plugin':''));
		$s = $r->execute($params);
		if (!$s) return false;
		if ($r->rowCount()) return true; #record updated!
		#no records were updated, it means that we should do insert instead
		$r = $this->prepare("INSERT INTO {$this->db_prefix}config (".(!empty($plugin)?'plugin,':'')."ckey,value) VALUES(".(!empty($plugin)?':plugin,':'').":ckey,:value)");
		$s = $r->execute($params);
		if (!$s) return false;
		if ($r->rowCount()) return true;
		#neither update or insert were successful
		return false;
	}
	/**
	* Method used to delete existing plugin in database.
	* @param string $key given plugin unique key.
	* @param string $plugin given plugin name; null by default.
	* @return bool TRUE.
	*/
	public function Delete_config($key, $plugin=null) {
		#prepare query params
		$params = array($key);
		if (!empty($plugin)) $params[] = $plugin;
		#try to delete record
		$r = $this->prepare("DELETE FROM {$this->db_prefix}config WHERE key=?".(!empty($plugin)?' and plugin=?':''));
		$s = $r->execute($params);
		if (!$s) return false;
		#even if rowCount is 0, it means that record with given parameters doesn't exist,
		#so we could say that config does not exist also (is deleted)
		return true;
	}
	#dictionary
	/**
	* Method used to obtain variables from the database by the site and the language. If variables already obtained earlier-no new
	* query to the database performed. Variables in this context is mentioned as GUI texts keys. For example in website "copyright" texts must be
	* displayed accordingly to user selected language. This requirement accomplished using variables for appropriate text rendition. 
	* @param string $ln given language to obtain variables; if not given - language obtained from current website configuration; default null.
	* @param bool $refresh indicates if variables should be obtained rather than using already available; default FALSE.
	* @return mixed $variables.
	*/
	public function Get_variables($ln=null, $refresh=false) {
		if (isset($this->variables[$ln]) && !$refresh) {
			return $this->variables[$ln];
		}
		if (is_null($ln)) $ln = $this->site->ln;
		if (!isset($this->site->data['languages'][$ln])) {
			return false;
		}
		$r = $this->prepare("SELECT * FROM {$this->db_prefix}variables WHERE (site=0 or site=?) and ln=?");
		$r->execute(array($this->site->data['id'], $ln));
		$variables = array();
		while ($d = $r->fetch()) {
			if (!empty($d['controller']) && !$this->plugins->Is_active($d['controller'])) continue;
			$variables[$d['vkey']] = $d['value'];
		}
		$this->variables[$ln] = $variables;
		return $variables;
	}
	/**
	* Method used to obtain variable from the $variables list by the key and the language.
	* @param mixed $variables is retrieved in function:
	* @see PC_core::Get_variables()
	* @param string $key given variable key used to look for variable key-value pairs in $variables array.
	* @ln string $ln given language name string. Using $key and $ln keys in the $variables array concrete vairiable should be found; default null.
	* @return mixed; if variable does not exist-false, otherwise $variable returned.
	*/
	public function Get_variable($key, $ln=null) {
		if (is_null($ln)) $ln = $this->site->ln;
		if (!isset($this->site->data['languages'][$ln])) {
			return false;
		}
		if (!isset($this->variables[$ln])) {
			if (!$this->Get_variables($ln)) return false;
		}
		if (!isset($this->variables[$ln][$key])) return false;
		return $this->variables[$ln][$key];
	}
	#database
	/**
	* Method used to convert string format like "lt:apie-mus-1,en:about-us-1" to array of {"lt" => "apie-mus-1", "en" => "about-us-1"}.
	* @param string $data given string to convert from. Given by reference.
	* @param string $separator given first level seperator for "explode()" function.
	* @param string $suseparator given second level seperator for "explode()" function.
	* @return bool TRUE if parse succeeded and FALSE if $data is not string.
	*/
	public function Parse_data_str(&$data, $separator=',', $subseparator=':') {
		if (!is_string($data)) return false;
		$_data = $data;
		$_data = explode($separator, $_data);
		$data = array();
		if (count($_data) && strpos($_data[0], $subseparator)) {
			for ($a=0; isset($_data[$a]); $a++) {
				$temp = explode($subseparator, $_data[$a]);
				$data[$temp[0]] = $temp[1];
			}
		}
		return true;
	}
	#output manager
	/**
	* Method used to start output buffering. Inside this method function "ob_start()" is called.
	*/
	public function Output_start() {
		ob_start();
	}
	/**
	* Method used to end output buffering and set given variable to buffer contents.
	* @param mixed $var variable used to set a reference to the buffer contents returned by "ob_get_clean()".
	* @return mixed $output which is buffer contents.
	*/	
	public function Output_end(&$var=null) {
		$output = ob_get_clean();
		if (isset($var)) $var = $output;
		return $output;
	}
	#search
	/**
	* Method used to search for keywords in website content which is stored in database. Is looking for any of whole keywords given to search
	* function. Search can be customized by giving additional parameters.
	* @param string $keyword given as a pattern to look for.
	* @param bool $all_lns given to indicate if should be looked for the given keyword in all languages; default FALSE.
	* @param mixed $seach_in given list of subpages ids to search as well.
	* @param mixed $date array "to" and "from" dates to search for in timely manner.
	* @result mixed bool FALSE if keyword not given or query to database resulted to null; and $result array when something was found by the keyword.
	* 
	* Future updates: integrate paging and sorting functionality.
	*/
	public function Search($keyword, $all_lns=false, $search_in=null, $date=null) {
		if (!is_null($search_in)) {
			if (!is_array($search_in)) $search_in = array((string)$search_in);
			#get all subnodes
			$search_in = $this->page->Get_subpages_list($search_in);
		}
		if (empty($keyword)) return false;
		$search_split = explode(" ", $keyword);
		$search_like = "";
		$search_arr = Array();
		foreach( $search_split as $idx => $word ) {
			$word = trim($word);
			if( empty($word) ) continue;
			$search_like .= ($search_like?" AND ":"") . "#fld# " . $this->sql_parser->like(":s$idx");
			$search_arr["s$idx"] = "%" . $word . "%";
		}
		$r = $this->prepare("SELECT c.*,p.*"
		." FROM {$this->db_prefix}content c"
		." LEFT JOIN {$this->db_prefix}pages p ON p.id=pid"
		." WHERE p.site=:site and deleted=0 and p.published=1 and (p.date_from is null or p.date_from<=:n) and (p.date_to is null or p.date_to>=:n)"
		.(count($search_in)?' and p.id in('.implode(',', $search_in).')':'')
		.(!empty($date['from'])?' and p.date>=:date_from':'')
		.(!empty($date['to'])?' and p.date<=:date_to':'')
		.($all_lns?"":" and ln='{$this->site->ln}'")
		." and ((" . str_replace("#fld#", "text", $search_like) . ")"
		." or (" . str_replace("#fld#", "name", $search_like) . ")"
		." or (" . str_replace("#fld#", "keywords", $search_like) . ")"
		." or (" . str_replace("#fld#", "description", $search_like) . "))");
		$search_filter = array(
			'site'=> $this->site->data['id'],
			'n'=> time()
		);
		#page date
		if (!empty($date['from'])) {
			if (!is_numeric($date['from'])) $date['from'] = strtotime($date['from']);
			$search_filter['date_from'] = $date['from'];
		}
		if (!empty($date['to'])) {
			if (!is_numeric($date['to'])) $date['to'] = strtotime($date['to']);
			$search_filter['date_to'] = $date['to'];
		}
		#execute
		$s = $r->execute(array_merge($search_filter, $search_arr));
		if (!$s) return false;
		$results = array();
		while ($d = $r->fetch()) {
			#$d['summary'] = $this->Summarize_search_results($d['text'], $keyword);
			$d['link'] = $this->site->Get_link($d['route'], $d['ln']);
			if (isset($d['text'])) $this->page->Parse_html_output($d['text']);
			if (isset($d['info'])) $this->page->Parse_html_output($d['info']);
			if (isset($d['info2'])) $this->page->Parse_html_output($d['info2']);
			if (isset($d['info3'])) $this->page->Parse_html_output($d['info3']);
			if ($all_lns) {
				$results[$d['ln']][] = $d;
			}
			else {
				$results[] = $d;
			}
		}
		return $results;
	}
	/**
	* Method used to shorten the string to given lenght and format result with HTML.
	* @param string $text to be shorten.
	* @param string $keyword in the $text. The $text is shorten from the ($keyword  position) - 20 in $text and total characters count to $length.
	* @param int $length given maximum length of $text.
	* @return string $text formatted text.
	*/
	public function Summarize_search_results($text, $keyword, $length=150) {
		$text = substr($text, strpos($text, $keyword)-20);
		$text = substr($text, 0, $length);
		$text = str_replace($keyword, '<b>'.$keyword.'</b>', $text);
		return $text;
	}
	#plugins
	/**
	* Method simply returns instance "PC_core" field "$editor" value.
	*/
	public function Get_editor() {
		return $this->editor;
	}
	/**
	* Method used to register field to the class instance field $editor. Field stored in this discipline:
	* editor['fields']['properties'][$plugin.'-'.$field].
	* @param string $field given as a key.
	* @param mixed $config; given value of the given field.
	* @return bool TRUE if field added and FALSE if not added due to invalid current plugin configuration.
	* @see PC_plugins::Get_currently_parsing() method executed in this method.
	*/
	public function Register_field($field, $config) {
		$plugin = $this->plugins->Get_currently_parsing();
		if (empty($plugin)) return false;
		if (!preg_match("/^[a-z0-9][a-z0-9_]{0,28}[a-z0-9]$/", $field)) return false;
		if (!is_array($config)) return false;
		$this->editor['fields']['properties'][$plugin.'-'.$field] = $config;
		return true;
	}
	
	/**
	* Method used to retrieve path by given parameters.
	* @param string $type given type of the path. A few available like "plugins" or "themes"
	* @param string $suffix given string to be ltrim'ed and added to end of the returned path.
	* @param mixed $param given parameter for specifing path by adding $param parameters at the end of the returned path string.
	* @return mixed; bool when function PC_site::Page_is_loaded() returns false and string containing path otherwise.
	* @see PC_site::Page_is_loaded()
	*/
	public function Get_path($type, $suffix='', $param=null) {
		if (!isset($this->cfg['path'][$type])) return false;
		$path = $this->cfg['path'][$type];
		switch ($type) {
			case 'plugins':
				if (is_null($param)) {
					if ($this->site instanceof PC_site) if (!$this->site->Page_is_loaded()) return false;
					$path .= $this->site->plugin.'/';
				}
				else $path .= (string)$param.'/';
				break;
			case 'themes':
				if (is_null($param)) {
					if (!$this->site->Is_loaded()) return false;
					$path .= $this->site->Get_theme_dir().'/';
				}
				else $path .= (string)$param.'/';
				break;
		}
		if (!empty($suffix)) $path .= ltrim((string)$suffix, '/');
		#templates, plugins, gallery
		return $path;
	}
	/**
	* Method used to get an URL by given parameters.
	* As this method inside calls {@link PC_core::Get_path()}. There are up to three parameters available to submit.
	* @param string $type given type of the path. A few available like "plugins" or "themes"
	* @param string $suffix='' given string to be ltrim'ed and added to end of the returned path.
	* @param mixed $param=null given parameter for specifing path by adding $param parameters at the end of the returned path string.
	* @return string URL by given parameters.
	*/
	public function Get_url() {
		$args = func_get_args();
		$path = call_user_func_array(array($this, 'Get_path'), $args);
		if (!$path) return false;
		return $this->cfg['url']['base'].substr($path, strlen($this->cfg['path']['system']));
	}
	#hooks
	/**
	* Method used to retrieve all registered hooks or only hooks by given $event name from instance variable _hooks.
	* @param string $event given name of hook to look for; default null.
	* @return mixed only hook or all hooks depending if $event was given.
	*/
	public function &Get_hooks($event=null) {
		if (!is_null($event)) {
			if (!isset($this->_hooks[$event])) {
				$false = false;
				return $false;
			}
			return $this->_hooks[$event];
		}
		return $this->_hooks;
	}
	/**
	* Method used simply add new record to instance $_hooks array.
	* @param string $event name of event to be stored in hooks array.
	* @param function name to be called when hook triggered.
	* @return bool allways TRUE.
	*/
	public function Register_hook($event, $callback) {
		#if (!is_callable($callback)) return false;
		$this->_hooks[(string)$event][] = $callback;
		return true;
	}
	/**
	* Method used to trigger the hook by event name. Inside this method is called "call_user_func()" function.
	* @param mixed $event given to look for the callback to be executed.
	* @param mixed $params given parameters array to pass to the callback function; default empty array.
	* @return bool TRUE if hook triggered; FALSE otherwise. 
	*/
	public function Init_hooks($event, $params=array()) {
		if (!isset($this->_hooks[(string)$event])) return false;
		if (!is_array($this->_hooks[(string)$event])) return false;
		foreach ($this->_hooks[(string)$event] as $callback) {
			call_user_func($callback, $params);
		}
		return true;
	}
	public function Count_hooks($event) {
		$hooks = $this->Get_hooks($event);
		if (!$hooks) return false;
		return count($hooks);
	}
	#callbacks (search ar naudojama kur nors?)
	public function Register_callback($event, $callback) {
		$this->_callbacks[(string)$event][] = $callback;
		return true;
	}
	public function Init_callback($event, $params=array()) {
		if (!isset($this->_callbacks[(string)$event])) return false;
		return call_user_func($this->_callbacks[(string)$event], $params);
	}
	//objects
	public function Get_object($className, $args=array(), $idIndex=0) {
		if (!is_array($args)) $args = array($args);
		$path = array('core', 'objects', $className);
		if (is_null($idIndex)) {
			$objects = $this->cache->Get($path);
			if (!$objects) $idIndex = 0;
			else $idIndex = count($objects);
		}
		elseif ($idIndex < 0) $idIndex = 0;
		$path[] = $idIndex;
		//return already created instance
		$object = $this->cache->Get($path);
		if ($object) return $object;
		//create new instance
		$reflectionCls = new ReflectionClass($className);
		return $this->cache->Cache($path, $reflectionCls->newInstanceArgs($args));
	}
	//params object
	public function Init_params(&$params) {
		if (!($params instanceof PC_params)) {
			$params = new PC_params($params);
		}
	}
}