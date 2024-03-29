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
abstract class PC_base {
	public $cfg, $path, $db, $db_prefix, $sql_parser, $core, $site, $routes, $route, $page, $cache, $plugins, $gallery, $auth;
	final public function __construct() {
		global $cfg, $db, $core, $site, $routes, $page, $cache, $plugins, $sql_parser, $gallery, $auth;
		$this->cfg =& $cfg;
		$this->path =& $cfg['path'];
		$this->db =& $db;
		$this->db_prefix =& $cfg['db']['prefix'];
		$this->sql_parser =& $sql_parser;
		$this->core =& $core;
		$this->site =& $site;
		$this->routes =& $routes;
		$this->route =& $routes->list;
		$this->page =& $page;
		$this->cache =& $cache;
		$this->plugins =& $plugins;
		$this->gallery =& $gallery;
		$this->auth =& $auth;
		//init custom subclass constructor
		if (method_exists($this, 'Init')) {
			$args = func_get_args();
			call_user_func_array(array($this, 'Init'), $args);
		}
	}
	protected function query($query) {
		return $this->db->query($query);
	}
	protected function prepare($query) {
		return $this->db->prepare($query);
	}
	protected function Output_start() {
		$args = func_get_args();
		call_user_func_array(array($this->core, 'Output_start'), $args);
	}
	protected function Output_end(&$var=null) {
		$this->core->Output_end($var);
	}
}