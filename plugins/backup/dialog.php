<?php
/** ProfisCMS - Opensource Content Management System Copyright (C) 2011 JSC "ProfIS"
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
$cfg['core']['no_login_form'] = true;
require_once '../../admin/admin.php';

$backup_folder = 'backups';
$bkpdir = '../../admin/'.$backup_folder.'/';
$MAX_QUERY_LENGTH = 20000;

function bkpfn_encode($f) {
	$f = urlencode($f);
	$f = preg_replace('/^\./s', '%2E', $f); // first dot
	return $f;
}

function bkpfn_decode($f) {
	$f = urldecode($f);
	return $f;
}

if (isset($_POST['ajax'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-cache');
	$relbd = $bkpdir;
	$out = array();
	
	// ***** RESTORE *****
	if (isset($_POST['restore'])) {
		$f = $relbd.basename($_POST['restore']);
		if ($z = fopen($f, 'rb')) {
			$fn_read = 'fread';
			$fn_close = 'fclose';
			$fn_eof = 'feof';
			do {
				// choose appropriate readers
				if (preg_match('/\.(\w+)$/', $f, $m)) {
					switch ($m[1]) {
					case 'bz2':
						if (!function_exists('bzopen')) {
							$out['error'] = "Bzip2 is not supported by this PHP installation";
							break 2;
						}
						fclose($z);
						$z = bzopen($f, 'r');
						$fn_read = 'bzread';
						$fn_close = 'bzclose';
						break;
					case 'gz':
						if (!function_exists('gzopen')) {
							$out['error'] = "Gzip (zlib) is not supported by this PHP installation";
							break 2;
						}
						fclose($z);
						$z = gzopen($f, 'rb');
						$fn_read = 'gzread';
						$fn_close = 'gzclose';
						$fn_eof = 'gzeof';
						break;
					}
				}
				$buf = '';
				// execute SQL
				do {
					$buf .= $fn_read($z, 8192);
					while (preg_match('/^(.*);[\r\n]+/sU', $buf, $m)) {
						$r = $db->prepare($m[1]);
						$s = $r->execute();
						if (!$s) {
							print_pre($r);
							print_pre($r->errorInfo());
							$out['error'] = $db->errorInfo();
							break 2;
						}
						$buf = substr($buf, strlen($m[0]));
					}
				} while (!$fn_eof($z));
				if (preg_match('/^(.*);\s*$/sU', $buf, $m)) // leftover
					if (!$db->query($m[1]))
						$out['error'] = $db->errorInfo();
			} while (0);
			$fn_close($z);
		}
		echo json_encode($out);
		return;
	}
	
	// ***** CREATE *****
	if (isset($_POST['create'])) {
		@mkdir($relbd);
		$t = time();
		$n = date('Ymd_His', $t).basename($_POST['create']);
		//generate random salt for the backup filename
		$abc = 'abcdefghijklmnopqrstuvwxyz';
		$salt = '';
		for ($a=0; $a<3; $a++) {
			$salt .= substr($abc, mt_rand(0, 25), 1);
		}
		$n .= $salt;
		$n = md5($n);
		$e = '.sql';
		if (function_exists('bzwrite')) {
			$e .= '.bz2';
			$z = bzopen($relbd.$n.$e, 'w');
			$fn_write = 'bzwrite';
			$fn_close = 'bzclose';
		} else if (function_exists('gzwrite')) {
			$e .= '.gz';
			$z = gzopen($relbd.$n.$e, 'wb9');
			$fn_write = 'gzwrite';
			$fn_close = 'gzclose';
		} else {
			$z = fopen($relbd.$n.$e, 'wb');
			$fn_write = 'fwrite';
			$fn_close = 'fclose';
		}
		if ($z) {
			$fn_write($z, '-- Database: `'.$cfg['db']['name']."`\n");
			$fn_write($z, '-- Timestamp: '.date('Y-m-d H:i:s', $t)."\n");
			$fn_write($z, '-- PHP version: '.phpversion()."\n");
			$fn_write($z, '-- Generated by ProfIS CMS '.PC_VERSION."\n");
			$fn_write($z, "\n");
			$tables = array();
			if ($cfg['db']['type'] == 'pgsql') $q = "SELECT table_name FROM information_schema.tables WHERE table_schema = '".$cfg['db']['name']."'";
			else $q = 'SHOW TABLES';
			if (@$cfg['db']['type']=='sqlite3') $q = "SELECT {$cfg['db']['prefix']}tbl_name FROM {$cfg['db']['prefix']}sqlite_master WHERE type='table'";
			$skip_tables = array('sqlite_sequence');
			$r = $db->query($q);
			if ($r) {
				while ($f = $r->fetch(PDO::FETCH_NUM))
					if (!in_array($f[0], $skip_tables))
						$tables[] = $f[0];
			}
			$xported = array();
			foreach ($tables as $k=>$table) {
				$fn_write($z, "\n");
				$fn_write($z, "\n");
				$fn_write($z, "\n");
				$fn_write($z, "-- ----------------------------------------\n");
				$fn_write($z, "-- $table\n");
				$fn_write($z, "-- ----------------------------------------\n");
				switch ($cfg['db']['type']) {
					case 'pgsql':
						$fn_write($z, "TRUNCATE TABLE $table;\n");
						break;
					case 'sqlite3':
						$q = "SELECT name,sql FROM sqlite_master WHERE type='table' AND tbl_name=".$table;
						$r = $db->query($q);
						if ($f = $r->fetch(PDO::FETCH_NUM)) $fn_write($z, $f[1].";\n");
						break;
					default:
						$fn_write($z, "DROP TABLE IF EXISTS $table;\n");
						$q = "SHOW CREATE TABLE $table";
						$r = $db->query($q);
						if ($f = $r->fetch(PDO::FETCH_NUM)) $fn_write($z, $f[1].";\n");
				}
				$r = $db->query("SELECT * FROM $table");
				if ($r) if ($f = $r->fetch()) {
					$fn_write($z, "\n");
					$accum = '';
					$ins = "INSERT INTO $table (".implode(',', array_keys($f)).") VALUES \n";
					do {
						foreach ($f as &$fd) {
							if (ctype_digit($fd)) continue;
							if (is_null($fd)) $fd = 'null';
							else $fd = $db->quote($fd);
						}
						$row = "\t(".implode(",", $f).")";
						if ($accum) {
							if (strlen($accum) + strlen($row) > $MAX_QUERY_LENGTH) {
								$fn_write($z, $accum.";\n");
								$accum = $ins.$row;
							} else
								$accum .= ",\n".$row;
						} else {
							$accum = $ins.$row;
						}
					} while ($f = $r->fetch());
					if ($accum) $fn_write($z, $accum.";\n");
				}
				$xported[] = $table;
				unset($tables[$k]);
			}
			if ($cfg['db']['type'] != 'pgsql') $fn_write($z, "\n\n\nOPTIMIZE TABLE `".implode('`, `', $xported)."`;\n");
			//restart postgre sequences
			if ($cfg['db']['type'] == 'pgsql') {
				$r = $db->prepare("SELECT c.relname FROM pg_class c WHERE c.relkind = 'S'");
				$s = $r->execute();
				if ($s) while ($seq = $r->fetchColumn()) {
					$value_r = $db->query("SELECT last_value FROM $seq");
					if ($value_r) {
						$seq_value = $value_r->fetchColumn();
						$fn_write($z, "ALTER SEQUENCE $seq RESTART WITH $seq_value;\n");
					}
				}
			}
			$fn_close($z);
		}
	}
	
	// ***** RENAME *****
	if (isset($_POST['rename']) && isset($_POST['fsname'])) {
		if ($_POST['fsname'] != 'reset.sql') {
			$n = bkpfn_encode($_POST['rename']);
			if (!file_exists($relbd.$n))
				rename($relbd.basename($_POST['fsname']), $relbd.$n);
		}
	}
	
	// ***** DELETE *****
	if (isset($_POST['delete'])) {
		foreach ((array)$_POST['delete'] as $f) {
			if ($f != 'reset.sql')
			@unlink($relbd.basename($f));
		}
	}
	// ***** GET *****
	foreach (glob($relbd.'*') as $f) {
		if (basename($f) == 'index.php') continue;
		if (@is_file($f)) {
			$t = filemtime($f);
			$s = filesize($f);
			$f = substr($f, strlen($relbd));
			$n = bkpfn_decode($f);
			$e = '';
			if (preg_match('#^(.*)(\.sql(\.\w+)?)$#i', $n, $m)) {
				$n = $m[1];
				$e = $m[2];
			}
			//if ($s)
				$out[] = array($f, $n, $e, date('Y-m-d H:i:s', $t), $s);
		}
	}
	echo json_encode($out);
	return;
}

$mod['name'] = 'Backup';
$mod['onclick'] = 'mod_backup_click()';
$mod['priority'] = 60;

?>
<style type="text/css">
.icon-restore {
	background-image: url("images/arrow-up.gif") !important;
}
.icon-ok32 {
	background: transparent url("images/ok32.png") no-repeat !important;
}
</style>
<script type="text/javascript">
Ext.namespace('PC.plugins');

function mod_backup_click() {
	var bkpdir = PC.global.BASE_URL + PC.global.ADMIN_DIR +'/'+  <?php echo json_encode($bkpdir); ?>;
	var add_fn = function() {
		// ***** CREATE *****
		Ext.Ajax.request({
			url: '<?php echo $cfg['url']['base'].$cfg['directories']['plugins']; ?>/backup/<?php echo basename(__FILE__) ?>',
			params: {
				ajax: '',
				create: '_manual'
			},
			method: 'POST',
			callback: function(opts, success, rspns) {
				if (success && rspns.responseText) {
					try {
						var data = Ext.decode(rspns.responseText);
						refresh_records(data);
						return; // OK
					} catch(e) {};
				}
				Ext.MessageBox.show({
					title: PC.i18n.error,
					msg: PC.i18n.mod.backup.error.create,
					buttons: Ext.MessageBox.OK,
					icon: Ext.MessageBox.ERROR
				});
			}
		});
	};
	var res_fn = function() {
		// ***** RESTORE *****
		var rec = grd.selModel.getSelected();
		Ext.MessageBox.show({
			buttons: Ext.MessageBox.YESNO,
			title: PC.i18n.msg.title.confirm,
			msg: String.format(PC.i18n.mod.backup.msg.restore, rec.data.name),
			icon: Ext.MessageBox.WARNING,
			maxWidth: 270,
			fn: function(btn_id) {
				if (btn_id == 'yes') {
					var res_rq = function() {
						Ext.Ajax.request({
							url: '<?php echo $cfg['url']['base'].$cfg['directories']['plugins']; ?>/backup/<?php echo basename(__FILE__) ?>',
							params: {
								ajax: '',
								restore: rec.data.fsname
							},
							method: 'POST',
							callback: function(opts, success, rspns) {
								if (success && rspns.responseText) {
									try {
										var data = Ext.decode(rspns.responseText);
										if (data.error) {
											Ext.MessageBox.show({
												title: String.format(PC.i18n.mod.backup.error.restore, ''),
												msg: data.error,
												buttons: Ext.MessageBox.OK,
												icon: Ext.MessageBox.ERROR
											});
										} else {
											Ext.MessageBox.show({
												title: PC.i18n.mod.backup.restart,
												msg: PC.i18n.mod.backup.msg.restore_ok,
												buttons: {ok: PC.i18n.mod.backup.restart},
												icon: 'icon-ok32',
												fn: function(btn, txt, opt) {
													location.reload();
												}
											});
										}
										return; // OK
									} catch(e) {};
								}
								Ext.MessageBox.show({
									title: PC.i18n.error,
									msg: String.format(PC.i18n.mod.backup.error.restore, ''),
									buttons: Ext.MessageBox.OK,
									icon: Ext.MessageBox.ERROR
								});
							}
						});
					};
					Ext.MessageBox.show({
						buttons: Ext.MessageBox.YESNOCANCEL,
						title: PC.i18n.mod.backup.selfname,
						msg: PC.i18n.mod.backup.msg.create_before_restore,
						icon: Ext.MessageBox.QUESTION,
						maxWidth: 270,
						fn: function(btn_id) {
							if (btn_id == 'no') res_rq();
							if (btn_id == 'yes')
								Ext.Ajax.request({
									url: '<?php echo $cfg['url']['base'].$cfg['directories']['plugins']; ?>/backup/<?php echo basename(__FILE__) ?>',
									params: {
										ajax: '',
										create: '_onrestore'
									},
									method: 'POST',
									callback: function(opts, success, rspns) {
										if (success && rspns.responseText) {
											try {
												var data = Ext.decode(rspns.responseText);
												refresh_records(data);
												res_rq();
												return; // OK
											} catch(e) {};
										}
										Ext.MessageBox.show({
											title: PC.i18n.error,
											msg: PC.i18n.mod.backup.error.create,
											buttons: Ext.MessageBox.OK,
											icon: Ext.MessageBox.ERROR
										});
									}
								});
						}
					});
				}
			}
		});
	};
	var del_fn = function() {
		// ***** DELETE *****
		Ext.MessageBox.show({
			buttons: Ext.MessageBox.YESNO,
			title: PC.i18n.msg.title.confirm,
			msg: PC.i18n.mod.backup.msg.del,
			icon: Ext.MessageBox.WARNING,
			maxWidth: 270,
			fn: function(btn_id) {
				if (btn_id == 'yes') {
					var dellist = [];
					grd.selModel.each(function(rec) {
						dellist.push(rec.data.fsname);
					});
					Ext.Ajax.request({
						url: '<?php echo $cfg['url']['base'].$cfg['directories']['plugins']; ?>/backup/<?php echo basename(__FILE__) ?>',
						params: {
							ajax: '',
							'delete[]': dellist
						},
						method: 'POST',
						callback: function(opts, success, rspns) {
							if (success && rspns.responseText) {
								try {
									var data = Ext.decode(rspns.responseText);
									var o = refresh_records(data);
									Ext.each(o, function(item, ndx, all) {
										dellist.remove(item);
									});
									if (dellist.length)
										Ext.MessageBox.show({
											title: PC.i18n.warning,
											msg: PC.i18n.mod.backup.error.del_some,
											buttons: Ext.MessageBox.OK,
											icon: Ext.MessageBox.WARNING
										});
									return; // OK
								} catch(e) {};
							}
							Ext.MessageBox.show({
								title: PC.i18n.error,
								msg: PC.i18n.mod.backup.error.del,
								buttons: Ext.MessageBox.OK,
								icon: Ext.MessageBox.ERROR
							});
						}
					});
				}
			}
		});
	};
	function refresh_records(data) {
		var old = {};
		grd.store.each(function(rec) {
			old[rec.data.fsname] = rec;
		});
		Ext.each(data, function(item, idx, all) {
			delete old[item[0]];
		});
		var o = [];
		for (var x in old) {
			o.push(old[x].data.fsname);
			grd.store.remove(old[x]);
		}
		try { // fixme?
			grd.store.loadData(data, true);
		} catch(e) {};
		grd.store.applySort();
		grd.store.fireEvent('datachanged', grd.store);
		return o;
	}
	
	var grd = new Ext.grid.EditorGridPanel({
		//title: PC.i18n.mod.backup.backup_restore,
		border: false,
		store: {
			xtype: 'arraystore',
			fields: ['fsname', 'name', 'ext', 'time', 'size'],
			idIndex: 0,
			data: [],
			sortInfo: {
				field: 'time',
				direction: 'DESC'
			}
		},
		columns: [
			{
				header: PC.i18n.mod.backup.file_name,
				dataIndex: 'name',
				sortable: true,
				editor: {
					listeners: {
						afterrender: function(ed) {
							ed.gridEditor.on('beforestartedit', function(editor, el, val) {
								if (this.record.data.fsname=='reset.sql') return false;
							});
							ed.gridEditor.on('startedit', function(be, val) {
								this.field.selectText();
							});
						}
					}
				},
				renderer: function(value, metaData, record, rowIndex, colIndex, store) {
					return (record.data.fsname=='reset.sql')?
						'<img style="vertical-align: -3px" src="images/brick.png" alt="" /> &nbsp;'+PC.i18n.mod.backup.reset_settings
						:record.data.name;
				},
				width: 200
			},{
				header: PC.i18n.mod.backup.created,
				dataIndex: 'time',
				sortable: true,
				width: 120
			},{
				header: PC.i18n.mod.backup.file_size,
				dataIndex: 'size',
				sortable: true,
				width: 70,
				renderer: function(value, metaData, record, rowIndex, colIndex, store) {
					return Ext.util.Format.fileSize(value);
				}
			},{
				header: PC.i18n.mod.backup.download,
				dataIndex: 'fsname',
				sortable: false,
				menuDisabled: true,
				width: 240,
				renderer: function(value, metaData, record, rowIndex, colIndex, store) {
					return '<a href="' + Ext.util.Format.htmlEncode(PC.global.BASE_URL + PC.global.ADMIN_DIR + '/<?php echo $backup_folder; ?>/' + encodeURIComponent(value)) + '" target="_blank">' + value + '</a>';
				}
			}
		],
		selModel: new Ext.grid.RowSelectionModel({
			moveEditorOnEnter: false,
			listeners: {
				selectionchange: function(sm) {
					grd.res_btn.setDisabled(sm.getCount() != 1);
					var dis = (sm.getCount() == 0);
					grd.del_btn.setDisabled(dis);
				}
			}
		}),
		listeners: {
			containerclick: function(g, e) {
				if (e.target == g.view.scroller.dom)
					this.getSelectionModel().clearSelections();
			},
			afteredit: function(ee) {
				if (ee.field == 'name') {
					// ***** RENAME *****
					Ext.Ajax.request({
						url: '<?php echo $cfg['url']['base'].$cfg['directories']['plugins']; ?>/backup/<?php echo basename(__FILE__) ?>',
						params: {
							ajax: '',
							fsname: ee.record.data.fsname,
							rename: ee.value + ee.record.data.ext
						},
						method: 'POST',
						callback: function(opts, success, rspns) {
							if (success && rspns.responseText) {
								try {
									var data = Ext.decode(rspns.responseText);
									refresh_records(data);
									return; // OK
								} catch(e) {};
							}
							ee.record.reject();
							Ext.MessageBox.show({
								title: PC.i18n.error,
								msg: String.format(PC.i18n.mod.backup.error.rename, ee.originalValue+ee.record.data.ext),
								buttons: Ext.MessageBox.OK,
								icon: Ext.MessageBox.ERROR
							});
						}
					});
				}
			},
			keypress: function(e) {
				if (e.getKey() === e.F2) {
					var sel = grd.getSelectionModel().getSelected();
					grd.startEditing(grd.store.indexOf(sel), grd.getColumnModel().findColumnIndex('name'));
				}
				if (e.getKey() === e.DELETE) del_fn();
			}
		},
		tbar: [
			{
				text: PC.i18n.mod.backup.backup,
				iconCls: 'icon-add',
				handler: add_fn
			},{
				text: PC.i18n.mod.backup.restore,
				iconCls: 'icon-restore',
				ref: '../res_btn',
				disabled: true,
				handler: res_fn
			},{
				text: PC.i18n.del,
				iconCls: 'icon-delete',
				ref: '../del_btn',
				disabled: true,
				handler: del_fn
			}
		]
	});
	
	var w = new Ext.Window({
		modal: true,
		//title: PC.plugin.backup.name,
		title: PC.i18n.mod.backup.backup_restore,
		width: 660,
		height: 350,
		layout: 'fit',
		items: [grd],
		/*items: [{
			xtype: 'tabpanel',
			activeTab: 0,
			border: false,
			items: [
				grd
			]
		}],*/
		buttons: [
			{
				text: PC.i18n.close,
				handler: function() {
					w.close();
				}
			}
		]
	});
	w.show();
	Ext.Ajax.request({
		url: '<?php echo $cfg['url']['base'].$cfg['directories']['plugins']; ?>/backup/<?php echo basename(__FILE__) ?>',
		params: {
			ajax: ''
		},
		method: 'POST',
		callback: function(opts, success, rspns) {
			if (success && rspns.responseText) {
				try {
					var data = Ext.decode(rspns.responseText);
					// *** LOAD DATA ***
					refresh_records(data);
					return; // OK
				} catch(e) {};
			}
			Ext.MessageBox.show({
				title: PC.i18n.error,
				msg: PC.i18n.msg.error.data.load,
				buttons: Ext.MessageBox.OK,
				icon: Ext.MessageBox.ERROR
			});
			w.close();
		}
	});
}

PC.plugin.backup = {
	name: PC.i18n.mod.backup.selfname,
	onclick: mod_backup_click,
	icon: <?php echo json_encode(get_plugin_icon()); ?>,
	priority: <?php echo $mod['priority']; ?>
};
</script>