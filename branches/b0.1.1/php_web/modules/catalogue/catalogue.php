<?php

/**
 * Catalogue.php
 *
 * The Catalogue module. 
 *
 * @author Evan Leybourn
 * @date 26-07-2008
 * 
 */
class Catalogue extends Modules {
	var $dobj;
	var $name = "Metabase";
	var $description = "The catalogue or metabase. This controls Irondata and describes the databases that are available to it.";
	
	function hook_pagetitle() {
		return "Metabase";
	}

	function hook_workspace() {
		return array("title"=>"Metabase Workspace", "path"=>"".$this->webroot()."catalogue/display");
	}
	
	/* The Query Source hook function. 
	 * Sends the query from the report generator to the source database.
	 */
	function hook_query_source($object_id, $query) {
		$object = $this->dobj->db_fetch($this->dobj->db_query("SELECT * FROM objects WHERE object_id='".$object_id."'; "));
		include_once($this->dir.$object['type']."/".$object['type'].".php");
		$obj = new $object['type']();
		if (is_array($query)) {
			$res = array();
			foreach ($query as $i => $q) {
				$res[$i] = $obj->hook_query_source($object_id, $q);
			}
		} else {
			$res = $obj->hook_query_source($object_id, $query);
		}
		return $res;
	}

	/* The Top Menu hook function. 
	 * Displays the module in the main menu. Or menu of primary functions. 
	 */
	function hook_top_menu() {
		return array(
			"databases" => "<a href='".$this->webroot()."catalogue/home'>Databases</a>"
			);
	}

	/* View Display 
	 * Displays the list of available databases in the catalogue.
	 */
	function view_display() {
		$query = "SELECT * FROM databases d, objects o WHERE o.object_id=d.object_id;";
		$res = $this->dobj->db_query($query);
		$dbs = $this->dobj->db_fetch_all($res);
		$output = Catalogue_View::view_display($dbs);
		return $output;
	}

	function view_home() {
		return $this->view_display();
	}

	function edit_database_validate($db, $obj) {
		if (empty($db['name'])) {
			$error['name'] = "Please enter a database name.";
		}
		if (empty($db['human_name'])) {
			$error['human_name'] = "Please enter a readable database name.";
		}
		if (empty($db['host'])) {
			$error['host'] = "Please enter the database host location.";
		}
// 		if (empty($db['username'])) {
// 			$error['username'] = "Please enter the database username.";
// 		}
// 		if (empty($db['password'])) {
// 			$error['password'] = "Please enter the database password.";
// 		}

		if (empty($error)) {
			$test_connection = $this->call_function($obj['type'], "test_connection", array($db));

			if (!empty($test_connection[$obj['type']])) {
				$error = array_merge((array)$error, (array)$test_connection[$obj['type']]);
			}
		}

		return $error;
	}
	
	/* View Add 
	 * Displays the basic add database form. Most modules will provide their own version of this form.
	 */
	function view_add($type="pgsql") {
		if ($_REQUEST['data']) {
			$obj = array();
			$obj['name'] = $_REQUEST['data']['name'];
			$obj['type'] = "pgsql";

			$db = array();
			$db['name'] = $_REQUEST['data']['name'];
			$db['host'] = $_REQUEST['data']['host'];
			$db['username'] = $_REQUEST['data']['username'];
			$db['human_name'] = $_REQUEST['data']['human_name'];
			$db['password'] = $_REQUEST['data']['password'];
			$db['description'] = $_REQUEST['data']['description'];
			$db['notes'] = $_REQUEST['data']['notes'];

			$error = $this->edit_database_validate($db, $obj);

			if (!empty($error)) {
				$dbs = $db;

				$output = Catalogue_View::view_add_edit($dbs, null, $error);
				return $output;
			} else {
				/* Save object */
				$object_id = $this->add_object($obj);

				$db['object_id'] = $object_id;

				/* Save database */
				$db_id = $this->add_database($db);
				$this->id = $db_id;
				$modules = $this->call_function($obj['type'], "hook_regen_schema", array($db_id, true));
			}
			$this->redirect('catalogue/edit_columns/'.$this->id);
			die();
		}
		if ($this->id) {
			$query = "SELECT * FROM databases d, objects o WHERE o.object_id=d.object_id AND database_id=".$this->id;
			$res = $this->dobj->db_query($query);
			$dbs = $this->dobj->db_fetch($res);
		} else {
			$dbs = false;
		}
		$output = Catalogue_View::view_add_edit($dbs, $type);
		return $output;
	}
	
	/**
	 * Remove Database
	 *
	 * Removes a database from the catalogue
	 */
	function view_remove() {
		if (empty($this->id)) return;

		$database_id = $this->id;

		$object_query = $this->dobj->db_fetch($this->dobj->db_query("SELECT object_id FROM databases WHERE database_id='$database_id' LIMIT 1;"));
		$object_id = $object_query['object_id'];

		$this->dobj->db_query("DELETE FROM objects WHERE object_id='$object_id';");

		$this->redirect('catalogue/home/');
	}
	
	/**
	 * View Database
	 *
	 * Displays the list of tables within the database
	 */
	function view_edit() {
		if (empty($this->id)) return;

		if ($_REQUEST['data']) {
			$query = "SELECT * FROM databases d, objects o WHERE o.object_id=d.object_id AND database_id=".$this->id;
			$res = $this->dobj->db_query($query);
			$dbs = $this->dobj->db_fetch($res);

			$obj = array();
			$obj['type'] = $dbs['type'];

			$db = array();
			$db['name'] = $dbs['name'];
			$db['host'] = $_REQUEST['data']['host'];
			$db['username'] = $_REQUEST['data']['username'];
			$db['human_name'] = $_REQUEST['data']['human_name'];
			$db['password'] = $_REQUEST['data']['password'];
			$db['description'] = $_REQUEST['data']['description'];
			$db['notes'] = $_REQUEST['data']['notes'];

			$error = $this->edit_database_validate($db, $obj);

			if (!empty($error)) {
				$dbs = $db;

				$output = Catalogue_View::view_add_edit($dbs, null, $error);
				return $output;
			} else {
				$this->save_database($db, $this->id);
			}

			$this->redirect('catalogue/edit_columns/'.$this->id);
			die();
		} else {
			$query = "SELECT * FROM databases d, objects o WHERE o.object_id=d.object_id AND database_id=".$this->id;
			$res = $this->dobj->db_query($query);
			$dbs = $this->dobj->db_fetch($res);

			$output = Catalogue_View::view_add_edit($dbs, $type);
			return $output;
		}
	}
	
	/** View Database 
	 * Displays the list of tables within the database
	 */
	function view_edit_columns() {
		/* Database */
		$query = "SELECT * FROM databases d, objects o WHERE o.object_id=d.object_id AND d.database_id='".$this->id."';";
		$res = $this->dobj->db_query($query);
		$dbs = $this->dobj->db_fetch($res);
		
		/* Tables */
		$query = "SELECT * FROM tables WHERE database_id='".$this->id."';";
		$res = $this->dobj->db_query($query);
		$tables_query = $this->dobj->db_fetch_all($res);

		foreach ($tables_query as $table_tmp) {
			$table_id = $table_tmp['table_id'];

			$tables[$table_id]['name'] = $table_tmp['human_name'];
		}

		/* Columns */
		$query = "SELECT t.human_name as table_human_name, c.human_name as column_human_name, * FROM tables t INNER JOIN columns c ON (c.table_id=t.table_id) WHERE t.database_id='".$this->id."' ORDER BY t.human_name, c.human_name;";
		$res = $this->dobj->db_query($query);
		$columns_query = $this->dobj->db_fetch_all($res);

		foreach ($columns_query as $columns_tmp) {
			$column_id = $columns_tmp['column_id'];

			$columns[$column_id]['name'] = $columns_tmp['table_human_name'].".".$columns_tmp['column_human_name'];
			$columns[$column_id]['type'] = $columns_tmp['data_type'];
		}

		foreach ($columns_query as $columns_tmp) {
			$column_id = $columns_tmp['column_id'];
			if ($columns_tmp['key_type'] == "PK") {
				$columns[$column_id]['key'] = "Primary";
			} else {
				$columns[$column_id]['key'] = "&nbsp;";
			}

			if (!empty($columns_tmp['references_column'])) {
				if (!empty($columns[$columns_tmp['references_column']])) {
					$columns[$column_id]['references'] = $columns[$columns_tmp['references_column']]['name'];
				} else {
					$columns[$column_id]['references'] = $columns_tmp['references_column'];
				}
			} else {
				$columns[$column_id]['references'] = "&nbsp;";
			}

			if ($columns_tmp['available'] == "t") {
				$columns[$column_id]['available'] = "Yes";
			} else {
				$columns[$column_id]['available'] = "No";
			}
		}

		/* Joins */
		$table_joins_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM table_joins WHERE table1='".implode("' OR table1='", array_keys($tables))."';"));

		if (!empty($table_joins_query)) {
			$columns_tmp = array();
			foreach ($table_joins_query as $table_join_tmp) {
				$columns_tmp = array_merge((array)$columns_tmp, (array)explode(",", $table_join_tmp['method']));
				$table_joins[$table_join_tmp['table_join_id']] = $table_join_tmp;
			}
			$columns_tmp = array_unique($columns_tmp);

			$columns_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT c.column_id, c.name as column_name, t.table_id, t.name as table_name FROM columns c INNER JOIN tables t ON (c.table_id=t.table_id) WHERE c.column_id='".implode("' OR c.column_id='", $columns_tmp)."';"));
			foreach ($columns_query as $column_tmp) {
				$join_columns[$column_tmp['column_id']] = $column_tmp;
				$join_tables[$column_tmp['table_id']] = $column_tmp;
			}

			foreach ($table_joins as $table_join) {
				$table_join_id = $table_join['table_join_id'];

				$method_tmp = explode(",", $table_join['method']);

				$method_start_table = $table_join['table1'];
				$method_end_table = $table_join['table2'];

				$this_pair_start_id = 0;
				$this_pair_end_id = $this_pair_start_id + 1;

				$last_pair_start_table = $method_start_table;

				unset($method_reorg);

				while ($method_tmp[$this_pair_start_id]) {
					$this_pair_start_table = $join_columns[$method_tmp[$this_pair_start_id]]['table_id'];

					if ($this_pair_start_id !== 0) $method_reorg[] = "internal join";

					if ($last_pair_start_table != $this_pair_start_table) {
						$method_reorg[] = $method_tmp[$this_pair_end_id];
						$method_reorg[] = "referenced by";
						$method_reorg[] = $method_tmp[$this_pair_start_id];
					} else {
						$method_reorg[] = $method_tmp[$this_pair_start_id];
						$method_reorg[] = "references";
						$method_reorg[] = $method_tmp[$this_pair_end_id];
					}

					$last_pair_start_table = $join_columns[$method_reorg[$this_pair_end_id]]['table_id'];

					$this_pair_start_id += 2;
					$this_pair_end_id = $this_pair_start_id + 1;
				}

				unset($method_tmp);
				foreach ($method_reorg as $method_step) {
					switch ($method_step) {
						case "internal join":
							$method_tmp .= " &#x21C4; ";
							break;
						case "references":
						case "referenced by":
							$method_tmp .= " <span style='font-style: italic;'>";
							$method_tmp .= $method_step;
							$method_tmp .= "</span> ";
							break;
						default:
							$join_column = $join_columns[$method_step];

							$method_tmp .= ucwords($join_column['table_name']);
							$method_tmp .= ".";
							$method_tmp .= ucwords($join_column['column_name']);
							break;
					}
				}

				$joins[$table_join_id]['name'] = $method_tmp;
			}
		}

		$output = Catalogue_View::view_edit_columns($dbs, $tables, $columns, $joins, $dbs['type']);
		return $output;
	}
	
	function add_object($data) {
		$data['object_id'] = $this->dobj->nextval("objects");
		$query = $this->dobj->insert($data, "objects");
		$this->dobj->db_query($query);
		return $data['object_id'];
	}
	
	function add_database($data) {
		$data['database_id'] = $this->dobj->nextval("databases");
		$query = $this->dobj->insert($data, "databases");
		$this->dobj->db_query($query);
		return $data['database_id'];
	}
	
	function save_database($data, $id=0) {
		if (!$id) {
			$id = $this->id;
		}
		$query = $this->dobj->update($data, "database_id", $id, "databases");
		$this->dobj->db_query($query);
		return $id;
	}
	
	function get_database($database_id) {
		$query = "SELECT * FROM databases d WHERE database_id='".$database_id."';";
		$database = $this->dobj->db_fetch($this->dobj->db_query($query));
		return $database;
	}

	function get_databases() {
		$query = "SELECT * FROM databases d;";
		$database = $this->dobj->db_fetch_all($this->dobj->db_query($query));
		return $database;
	}

	function get_tables($object_id) {
		$query = "SELECT t.name, t.table_id FROM tables t, databases d WHERE d.database_id=t.database_id AND d.object_id='".$object_id."' ORDER BY t.name;";
		$tables = $this->dobj->db_fetch_all($this->dobj->db_query($query));
		return $tables;
	}

	/* TODO: Fix to display from only one database */
	function get_structure($object, $available=true) {
		if ($available) {
			$available = " AND c.available='t' ";
		}
		if (!is_array($object)) {
			$object = array($object);
		}
		$query = "SELECT t.human_name as table_name, t.name as table_sql_name, t.table_id, c.human_name as column_name, c.name as column_sql_name, c.column_id, c.data_type, c.dropdown FROM databases d, tables t, columns c WHERE d.object_id=".$object[0]." AND d.database_id=t.database_id AND c.table_id=t.table_id ".$available." ORDER BY t.table_id, c.human_name;";
		$columns = $this->dobj->db_fetch_all($this->dobj->db_query($query));
		$arr = array();
		foreach ($columns as $i => $column) {
			$go = true;
			if (array_key_exists(1, $object) && $object[1]) {
				foreach ($object[1] as $j => $ex) {
					if ($column['column_id'] == $ex['column_id']) {
						$go = false;
					}
				}
			}
			if ($go) {
				$arr[$column['table_name']][$column['column_name']] = $column;
			}
		}
		return $arr;
	}

	function add_table($data) {
		$data['table_id'] = $this->dobj->nextval("tables");
		$query = $this->dobj->insert($data, "tables");
		$this->dobj->db_query($query);
		return $data['table_id'];
	}

	function save_table($data, $id=0) {
		if (!$id) {
			$id = $this->id;
		}
		$query = $this->dobj->update($data, "table_id", $id, "tables");
		$this->dobj->db_query($query);
		return $id;
	}
	function get_columns($table_id) {
		$query = "SELECT * FROM columns c WHERE table_id='".$table_id."';";
		$columns = $this->dobj->db_fetch_all($this->dobj->db_query($query));
		return $columns;
	}
	
	function add_column($data) {
		$data['column_id'] = $this->dobj->nextval("columns");
		$query = $this->dobj->insert($data, "columns");
		$this->dobj->db_query($query);
		return $data['column_id'];
	}
	
	function save_column($data, $id=0) {
		if (!$id) {
			$id = $this->id;
		}

		$data['available'] = ($data['available'] == "on" ? "t" : "f");
		$data['dropdown'] = ($data['dropdown'] == "on" ? "t" : "f");

		$query = $this->dobj->update($data, "column_id", $id, "columns");
		$this->dobj->db_query($query);
		return $id;
	}

	function view_edit_column() {
		$column_id = $this->id;

		if (empty($column_id)) return;

		$column = $this->dobj->db_fetch($this->dobj->db_query("SELECT c.*, t.database_id FROM columns c INNER JOIN tables t ON (t.table_id=c.table_id) WHERE c.column_id='$column_id';"));
		$database_id = $column['database_id'];

		if (!empty($_REQUEST['data'])) {
			$this->save_column($_REQUEST['data'], $column_id);

			$this->redirect("catalogue/edit_columns/$database_id");
		}

		$output = Catalogue_View::view_edit_column($column, $database_id);
		return $output;
	}

	function view_submit_all_columns_available() {
// 		if ($this->id) {
// 			$this->dobj->db_query("UPDATE columns SET available=true WHERE table_id IN (SELECT table_id FROM tables WHERE database_id='".$this->id."');");
// 		}
// 
// 		$this->redirect("catalogue/edit/".$this->id);
	}
}

Class Catalogue_View {
	
	/* View Display 
	 * Displays the list of available databases in the catalogue.
	 */
	function view_display($dbs) {
		$output->title = "Databases";
		$output->data = $this->l("catalogue/add", "Add Database");

		if (is_array($dbs)) {
			$output->data .= "
				<div class='reports'>
					<table cellpadding='0' cellspacing='0'>
						<tr>
							<th>Name</th>
							<th>Description</th>
							<th>Host</th>
							<th>&nbsp;</th>
						</tr>
						";

			foreach ($dbs as $i => $db) {
				$output->data .= $this->theme_metabase($db);
			}

			$output->data .= "
					</table>
				</div>
				";
		} else {
			$output->data .= "<p>No Databases can be found.</p>";
		}

		return $output;
	}

	/* View Add 
	 * Displays the basic add database form. Most modules will provide their own version of this form.
	 */
	function view_add_edit($dbs, $types, $error=null) {
		$output = "";

		if ($this->action == "add") {
			$output->title = "Add Database";
		} else if ($this->action == "edit") {
			$output->title = "Edit Database";
		}

		$output->data = "";

		if ($this->action == "add") {
			if ($this->id) {
				$output->data .= $this->f('catalogue/add/'.$this->id);
			} else {
				$output->data .= $this->f('catalogue/add');
			}
		} else if ($this->action == "edit") {
			$output->data .= $this->f('catalogue/edit/'.$this->id);
		}

		$output->data .= "<div dojoType='dojo.data.ItemFileReadStore' url='".$this->webroot()."catalogue/type_dd_json' jsId='type_store'></div>";

		if ($this->id) {
			$disabled = "true";
		} else {
			$disabled = "false";
		}

		if (!empty($error['error'])) $output->data .= $this->p($error['error'], "error");

		$output->data .= $this->i("data[type]", array("disabled"=>/*$disabled*/"true", "label"=>"Database Type", "default"=>"pgsql", "dojoType"=>"dijit.form.FilteringSelect", "store"=>"type_store"));

		$output->data .= $this->i("data[name]", array("disabled"=>$disabled, "label"=>"Name", "default"=>$dbs['name'], "dojo"=>"dijit.form.TextBox"));
		if (!empty($error['name'])) $output->data .= $this->p($error['name'], "error");

		$output->data .= $this->i("data[human_name]", array("label"=>"Readable Name", "default"=>$dbs['human_name'], "dojo"=>"dijit.form.TextBox"));
		if (!empty($error['human_name'])) $output->data .= $this->p($error['human_name'], "error");

		$output->data .= $this->i("data[host]", array("label"=>"Host", "default"=>$dbs['host'], "dojo"=>"dijit.form.TextBox"));
		$output->data .= $this->p("IP address or domain name of database.");
		if (!empty($error['host'])) $output->data .= $this->p($error['host'], "error");

		$output->data .= $this->i("data[username]", array("label"=>"Username", "default"=>$dbs['username'], "dojo"=>"dijit.form.TextBox"));
		if (!empty($error['username'])) $output->data .= $this->p($error['username'], "error");

		$output->data .= $this->i("data[password]", array("label"=>"Password", "default"=>$dbs['password'], "dojo"=>"dijit.form.TextBox"));
		if (!empty($error['password'])) $output->data .= $this->p($error['password'], "error");

		$output->data .= $this->i("data[description]", array("label"=>"Description", "default"=>$dbs['description'], "dojo"=>"dijit.form.Textarea"));

		$output->data .= $this->i("data[notes]", array("label"=>"Notes", "default"=>$dbs['notes'], "dojo"=>"dijit.form.Textarea"));

// 		$output->data .= $this->i("data[ignore]", array("label"=>"Which columns should be ignored for joins (e.g. modified_by)", "dojo"=>"dijit.form.Textarea"));

// 		$output->data .= $this->i("", array("label"=>"", "type"=>"button", "value"=>"Cancel", "dojoType"=>"dijit.form.Button", "onclick"=>"window.location=\"".$this->webroot()."catalogue/home\"; return false;"));
// 		$output->data .= $this->i("submit", array("label"=>"", "type"=>"submit", "value"=>"Next", "dojoType"=>"dijit.form.Button"));

		$output->data .= "
			<div class='input'>
				<button value='Cancel' dojoType='dijit.form.Button' onclick='window.location=\"".$this->webroot()."catalogue/home\"; return false;' name='cancel' />Cancel</button><button type='submit' value='Next' dojoType='dijit.form.Button' name='submit' />Next</button>
			</div>
			";

		$output->data .= $this->f_close();
		$output->data = "<div dojoType='dojox.layout.ContentPane' layoutAlign='client'>".$output->data."</div>";
		return $output;
	}
	
	/**
	 * View Database
	 *
	 * Displays the list of tables within the database
	 *
	 * @return HTML to be displayed
	 */
	function view_edit() {
	}
	
	/* View Database 
	 * Displays the list of tables within the database
	 */
	function view_edit_columns($dbs, $tables, $columns, $joins, $type) {
		$output = "";
		$output->title = "Edit Database";

		$output->data .= "<h3>Columns</h3>";
// 		$output->data .= $this->l("catalogue/submit_all_columns_available/".$this->id, "Make All Columns Available");

		$output->data .= "
			<div class='reports'>
				<table cellpadding='0' cellspacing='0'>
					<tr>
						<th>Column</th>
						<th>Type</th>
						<th>Key</th>
						<th>References</th>
						<th>Available</th>
						<th>&nbsp;</th>
					</tr>
					";
		foreach ($columns as $column_id => $db) {
			$output->data .= "
					<tr>
						<td>".$db['name']."</td>
						<td>".$db['type']."</td>
						<td>".$db['key']."</td>
						<td>".$db['references']."</td>
						<td>".$db['available']."</td>
						<td>
							<ul>
								<li>".$this->l("catalogue/edit_column/$column_id", "Edit")."</li>
							</ul>
						</td>
					</tr>
					";
		}
		$output->data .= "
				</table>
			</div>
			";

		$output->data .= "
			<div class='input'>
				<button value='Back' dojoType='dijit.form.Button' onclick='window.location=\"".$this->webroot()."catalogue/edit/".$this->id."\"; return false;' name='back' />Back</button><button value='Done' dojoType='dijit.form.Button' onclick='window.location=\"".$this->webroot()."catalogue/home\"; return false;' name='done' />Done</button>
			</div>
			";

		return $output;
	}

	function view_edit_column($column, $database_id) {
		$output->title = "Edit Column";
		$output->data .= $this->f('catalogue/edit_column/'.$this->id);

		$output->data .= $this->i("data[human_name]", array("label"=>"Name", "default"=>$column['human_name'], "dojo"=>"dijit.form.TextBox"));
		$output->data .= $this->i("data[description]", array("label"=>"Description", "default"=>$column['description'], "dojo"=>"dijit.form.Textarea"));

		$output->data .= "<hr />";

		$output->data .= $this->i("data[available]", array("label"=>"Available", "type"=>"checkbox", "default"=>($column['available'] == "t")));

		$output->data .= $this->i("data[dropdown]", array("label"=>"Dropdown Constraints", "type"=>"checkbox", "default"=>($column['dropdown'] == "t")));
		$output->data .= $this->p("Provide a drop-down menu of values when creating constraints.");

		$output->data .= "<hr />";

		$output->data .= "
			<div class='input'>
				<button value='Cancel' dojoType='dijit.form.Button' onclick='window.location=\"".$this->webroot()."catalogue/edit_columns/$database_id\"; return false;' name='cancel' />Cancel</button><button type='submit' value='Next' dojoType='dijit.form.Button' name='submit' />Save</button>
			</div>
			";
		$output->data .= $this->f_close();

		return $output;
	}
}

?>
