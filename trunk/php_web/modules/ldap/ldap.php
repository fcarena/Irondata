<?php

/**
 * Ldap.php
 *
 * The LDAP authenitcation module
 *
 * @author Evan Leybourn
 * @date 26-07-2008
 *
 */

class Ldap extends User {
	var $dobj;
	var $name = "LDAP";
	var $description = "LDAP";
	var $ldaphost = '192.168.25.38';

	function hook_admin_tools() {
		return null;
	}

	function hook_auth() {
		return null;
	}

	function hook_login($usr=null, $pwd=null) {
		return $this->ldapAuthenticated($usr, $pwd);
	}

	function hook_login_priority() {
		return null;
	}

	function hook_roles() {
		return null;
	}


	/**
	 * Called by User::view_login. Attempts to check the provided login details against users from the ldap server. If successful, saves the user's details and acl to the session variable and redirects to the appropriate page.
	 */
	function ldapAuthenticated($usr=null, $pwd=null) {
		if (!$pwd) {
			return false;
		}
		#Connect to the ldap server
		$ds=ldap_connect($this->ldaphost);
		if ($ds) {
			#Bind anonymously. Used to find the dn of the user. 
			$r = ldap_bind($ds);
			#Search for the user. 
			$sr = ldap_search($ds, "o=ieaust", "cn=$usr");

			#Get all results. 
			$info = ldap_get_entries($ds, $sr);

			foreach ($info as $i => $info_row) {
				#Do not attempt to bind if the record has no DN (ie system records).
				if (!($info_row['dn'])) {
					continue;
				}
				$fulldn = $info_row['dn'];

				#Check that the supplied user can bind/login with the supplied password. 
				if (@ldap_bind($ds, $fulldn, $pwd)) {
					ldap_close($ds);

					//create an array of organisational units to which the user belongs
					if ($info_row['ou']['count'] > 1) {
						foreach ($info_row['ou'] as $unit_index => $unit) {
							if ($unit_index == "count") continue;
							if (empty($unit)) continue;

							$units[$unit] = $unit;
						}
					} else if ($info_row['ou']['count'] > 0 && !empty($info_row['ou'][0])) {
						$unit = strtolower($info_row['ou'][0]);

						$units[$unit] = $unit;
					}

					//get permissions for the user and their groups
					$acls_users_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM system_acls_ldap_users WHERE user_id='".$usr."' AND access=true;"));
					$acls_groups_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM system_acls_ldap_groups WHERE group_id='".implode("' OR group_id='", $units)."' AND access=true;"));

					//convert the user's and user's groups acls into a single array telling if the user has permission or not for each role.
					$acls_query = array_merge((array)$acls_users_query, (array)$acls_groups_query);
					foreach ($acls_query as $acl_tmp) {
						if (empty($acl_tmp)) continue;

						$role_id = $acl_tmp['role'];

						$acls['system'][$role_id] = true;
					}

					//save in the session and redirect
					$_SESSION['user'] = "ldap_$usr";
					$_SESSION['acls']['system'] = $acls['system'];

					//set the report acl
					$this->call_function("ldap", "set_session_report_acls", array());

					//redirect to front page or last page
					$this->redirect($_SESSION['premodule'].'/'.$_SESSION['preaction']);
				}
			}
			ldap_close($ds);
		}

		//If we get to this point then authentication by ldap has failed. *Hangs head in shame
		return false;
	}

	/**
	 * Sets report ACLs in $_SESSION. Called at login by User::hook_login and whenever a new report is cerated, so that the permissions for the new report are recognised
	 */
	function set_session_report_acls() {
		//only use set_session_report_acls in this module if an ldap user is logged in (as opposed to a database user)
		if (substr($_SESSION['user'], 0, 5) != "ldap_") return;

		//get the user_id
		$usr = substr($_SESSION['user'], 5);

		$search_string = "(uid=$usr)";

		#Connect to the ldap server
		$ds=ldap_connect($this->ldaphost);
		if ($ds) {
			$r = ldap_bind($ds);
			//search the directory for the logged in user id
			$sr = ldap_search($ds, "o=ieaust", $search_string, array("uid", "ou"));

			$ldap_entries = ldap_get_entries($ds, $sr);

			foreach ($ldap_entries as $entry) {
				//create an array of organisatinoal units to which the user belongs
				if ($entry['ou']['count'] > 1) {
					foreach ($entry['ou'] as $unit_index => $unit) {
						if ($unit_index == "count") continue;
						if (empty($unit)) continue;

						$units[$unit] = $unit;
					}
				} else if ($entry['ou']['count'] > 0 && !empty($entry['ou'][0])) {
					$unit = strtolower($entry['ou'][0]);

					$units[$unit] = $unit;
				}
			}

			ldap_close($ds);
		}

		//get report permissions for the user and their groups
		$rep_acls_users_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM report_acls_ldap_users WHERE user_id='".$usr."' AND access=true;"));
		$rep_acls_groups_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM report_acls_ldap_groups WHERE group_id='".implode("' OR group_id='", $units)."' AND access=true;"));

		//convert the user's and user's groups acls into a single array telling if the user has permission or not for each role of each report.
		$rep_acls_query = array_merge((array)$rep_acls_users_query, (array)$rep_acls_groups_query);
		foreach ($rep_acls_query as $acl_tmp) {
			if (empty($acl_tmp)) continue;

			$template_id = $acl_tmp['template_id'];
			$role_id = $acl_tmp['role'];

			$acls['report'][$template_id][$role_id] = true;
		}

		//save the report acl in $_SESSION
		$_SESSION['acls']['report'] = $acls['report'];
	}

	/**
	 * Called by Tabular::view_execute_scheduled. gets the list of ldap recipients for a given report, and fetches email addresses and full names
	 */
	function hook_recipients($template_id, $template_recipients=null) {
		//fetch recipients from database
		$recipients_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM ldap_recipients WHERE template_id='".$template_id."';"));

		//rekey by uid
		if (!empty($recipients_query)) {
			foreach ($recipients_query as $recipient) {
				$uid = $recipient['uid'];

				$recipients[$uid] = true;
			}
		}

		if (empty($recipients)) {
			return null;
		}

		$search_string = "(|(uid=".implode(")(uid=", array_keys($recipients))."))";

		#Connect to the ldap server
		$ds=ldap_connect($this->ldaphost);
		if ($ds) {
			$r = ldap_bind($ds);
			$sr = ldap_search($ds, "o=ieaust", $search_string, array("uid", "fullname", "mail"));

			$ldap_entries = ldap_get_entries($ds, $sr);

			foreach ($ldap_entries as $entry) {
				if (empty($entry['uid'][0])) continue;
				if (empty($entry['fullname'][0])) continue;
				if (empty($entry['mail'][0])) continue;

				$uid = $entry['uid'][0];
				$name = $entry['fullname'][0];
				$email = strtolower($entry['mail'][0]);

				//create an array of full names and email addresses
				$users[] = array($name, $email);
			}

			ldap_close($ds);
		}

		return $users;
	}

	function hook_recipient_selector() {
		$recipients_count = $this->dobj->db_fetch($this->dobj->db_query("SELECT count(uid) FROM ldap_recipients WHERE template_id='".$this->id."';"));

		$recipients_count = $recipients_count['count'];

		if ($recipients_count == "1") {
			$recipients = "1 recipient";
		} else {
			$recipients = "$recipients_count recipients";
		}

		return "
			<div class='input' style='margin-left: 30px;'><button type='submit' name='data[ldap]' id='ldap_recipients' value='ldap' dojoType='dijit.form.Button' />LDAP</button><span id='ldap_recipients_count' style='padding-left: 20px; vertical-align: middle; color: #555753; font-size: 10pt; font-style: italic;'>$recipients</span></div>
			";
	}

	/**
	 * Called by User::view_access to fetch users, groups, group memberships by user - then translate them to usable arrays for the acl.
	 */
	function hook_access_users() {
		//get data from the ldap server
		$ds=ldap_connect($this->ldaphost);
		if ($ds) {
			$r = ldap_bind($ds);
			//get all ids, names and organisational units
			$sr = ldap_search($ds, "o=ieaust", "(&(mail=*)(fullname=*))", array("uid", "fullname", "sn", "ou"));

			//sort by second name
			ldap_sort($ds, $sr, "sn");

			$ldap_entries = ldap_get_entries($ds, $sr);

			//loop through all returned users
			foreach ($ldap_entries as $entry) {
				if (empty($entry['uid'][0])) continue;
				if (empty($entry['fullname'][0])) continue;

				$uid = "ldap_".strtolower($entry['uid'][0]);
				$name = $entry['fullname'][0];

				//create arrays of organisational units, and ou memberships by user
				if ($entry['ou']['count'] > 1) {
					foreach ($entry['ou'] as $unit_index => $unit) {
						if ($unit_index == "count") continue;
						if (empty($unit)) continue;

						$unit_id = "ldap_".$unit;

						$units[$unit_id] = $unit;

						$uid_units[$uid][] = $unit_id;
					}
				} else if ($entry['ou']['count'] > 0 && !empty($entry['ou'][0])) {
					$unit = strtolower($entry['ou'][0]);
					$unit_id = "ldap_".$unit;

					$units[$unit_id] = $unit;

					$uid_units[$uid][] = $unit_id;
				}

				//cerate an array of users
				$users[$uid] = $name;
			}

			ldap_close($ds);

			asort($units);

			return array(
				"users" => $users,
				"groups" => $units,
				"users_groups" => $uid_units
				);
		}

		return;
	}


	/**
	 * Called by User::view_access to fetch user and group acls
	 */
	function hook_access_acls() {
		//get data from the database
		$acls_users_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT 'ldap_'||user_id as user_id, role, access FROM system_acls_ldap_users WHERE access=true;"));
		$acls_groups_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT 'ldap_'||group_id as group_id, role, access FROM system_acls_ldap_groups WHERE access=true;"));

		return array(
			"acls" => array(
				"users" => $acls_users_query,
				"groups" => $acls_groups_query
				)
			);
	}

	/**
	 * Called by Tabular::view_add to fetch user and group acls for a given report
	 */
	function hook_access_report_acls($template_id) {
		if (empty($template_id)) return;

		$acls_users_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT 'ldap_'||user_id as user_id, role, access FROM report_acls_ldap_users WHERE access=true AND template_id='$template_id';"));
		$acls_groups_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT 'ldap_'||group_id as group_id, role, access FROM report_acls_ldap_groups WHERE access=true AND template_id='$template_id';"));

		return array(
			"acls" => array(
				"users" => $acls_users_query,
				"groups" => $acls_groups_query
				)
			);
	}

	/**
	 * Called by User::view_access_submit to save edited acl
	 */
	function hook_access_submit($acls) {
		//get existing aces from the database
		$acls_users_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT user_id as user_id, role, access FROM system_acls_ldap_users WHERE access=true;"));
		$acls_groups_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT group_id as group_id, role, access FROM system_acls_ldap_groups WHERE access=true;"));

		//convert acl into a readable array. from this we remove the aces that are unchanged. then we can delete the aces that are no longer selected
		foreach (array("users" => $acls_users_query, "groups" => $acls_groups_query) as $users_meta_key => $acls_delete_tmp) {
			if (!empty($acls_delete_tmp)) {
				foreach ($acls_delete_tmp as $acl_delete_tmp) {
					if ($users_meta_key == "users") {
						$user_id = $acl_delete_tmp['user_id'];
					} else if ($users_meta_key == "groups") {
						$user_id = $acl_delete_tmp['group_id'];
					}

					$role_id = $acl_delete_tmp['role'];

					$acls_delete[$users_meta_key][$user_id][$role_id] = true;
				}
			}
		}

		//loop through data we got back from the form
		if (!empty($acls)) {
			foreach ($acls as $users_meta_key => $users) {
				foreach ($users as $user_id => $roles) {
					//if this user isn't an ldap user, ignore
					if (substr($user_id, 0, 5) != "ldap_") continue;

					$user_id = substr($user_id, 5);

					foreach ($roles as $role_id => $access) {
						//if the current ace does not exist in the old acl, then add it to the list of new aces
						if (empty($acls_delete[$users_meta_key][$user_id][$role_id])) {
							$acls_insert[$users_meta_key][$user_id][$role_id] = true;
						//otherwise the ace has not been changed: remove it from the list of aces to delete
						} else {
							unset($acls_delete[$users_meta_key][$user_id][$role_id]);
						}
					}
				}
			}
		}

		//loop through the list of aces to remove
		if (!empty($acls_delete)) {
			foreach ($acls_delete as $users_meta_key => $users) {
				foreach ($users as $user_id => $roles) {
					foreach ($roles as $role_id => $access) {
						if (empty($access)) continue;

						if ($users_meta_key == "users") {
							//remove ace from the database
							$this->dobj->db_query("DELETE FROM system_acls_ldap_users WHERE user_id='$user_id' AND role='$role_id';");
						} else if ($users_meta_key == "groups") {
							//remove ace from the database
							$this->dobj->db_query("DELETE FROM system_acls_ldap_groups WHERE group_id='$user_id' AND role='$role_id';");
						}
					}
				}
			}
		}

		//loop through the list of aces to add
		if (!empty($acls_insert)) {
			foreach ($acls_insert as $users_meta_key => $users) {
				foreach ($users as $user_id => $roles) {
					foreach ($roles as $role_id => $access) {
						if (empty($access)) continue;

						if ($users_meta_key == "users") {
							//add the ace to the database
							$this->dobj->db_query($this->dobj->insert(array("user_id"=>$user_id, "role"=>$role_id), "system_acls_ldap_users"));
						} else if ($users_meta_key == "groups") {
							//add the ace to the database
							$this->dobj->db_query($this->dobj->insert(array("group_id"=>$user_id, "role"=>$role_id), "system_acls_ldap_groups"));
						}
					}
				}
			}
		}

		return;
	}

	/**
	 * Called by Tabular::view_save to save edited acl for a given report
	 */
	function hook_access_report_submit($acls, $template_id) {
		//get existing aces from the database
		$acls_users_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT user_id as user_id, role, access FROM report_acls_ldap_users WHERE access=true AND template_id='$template_id';"));
		$acls_groups_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT group_id as group_id, role, access FROM report_acls_ldap_groups WHERE access=true AND template_id='$template_id';"));

		//convert acl into a readable array. from this we remove the aces that are unchanged. then we can delete the aces that are no longer selected
		foreach (array("users" => $acls_users_query, "groups" => $acls_groups_query) as $users_meta_key => $acls_delete_tmp) {
			if (!empty($acls_delete_tmp)) {
				foreach ($acls_delete_tmp as $acl_delete_tmp) {
					if ($users_meta_key == "users") {
						$user_id = $acl_delete_tmp['user_id'];
					} else if ($users_meta_key == "groups") {
						$user_id = $acl_delete_tmp['group_id'];
					}

					$role_id = $acl_delete_tmp['role'];

					$acls_delete[$users_meta_key][$user_id][$role_id] = true;
				}
			}
		}

		//loop through data we got back from the form
		if (!empty($acls)) {
			foreach ($acls as $users_meta_key => $users) {
				foreach ($users as $user_id => $roles) {
					//if this user isn't an ldap user, ignore
					if (substr($user_id, 0, 5) != "ldap_") continue;

					$user_id = substr($user_id, 5);

					foreach ($roles as $role_id => $access) {
						//if the current ace does not exist in the old acl, then add it to the list of new aces
						if (empty($acls_delete[$users_meta_key][$user_id][$role_id])) {
							$acls_insert[$users_meta_key][$user_id][$role_id] = true;
						//otherwise the ace has not been changed: remove it from the list of aces to delete
						} else {
							unset($acls_delete[$users_meta_key][$user_id][$role_id]);
						}
					}
				}
			}
		}

		//loop through the list of aces to remove
		if (!empty($acls_delete)) {
			foreach ($acls_delete as $users_meta_key => $users) {
				foreach ($users as $user_id => $roles) {
					foreach ($roles as $role_id => $access) {
						if (empty($access)) continue;

						if ($users_meta_key == "users") {
							//remove ace from the database
							$this->dobj->db_query("DELETE FROM report_acls_ldap_users WHERE user_id='$user_id' AND template_id='$template_id' AND role='$role_id';");
						} else if ($users_meta_key == "groups") {
							//remove ace from the database
							$this->dobj->db_query("DELETE FROM report_acls_ldap_groups WHERE group_id='$user_id' AND template_id='$template_id' AND role='$role_id';");
						}
					}
				}
			}
		}

		//loop through the list of aces to add
		if (!empty($acls_insert)) {
			foreach ($acls_insert as $users_meta_key => $users) {
				foreach ($users as $user_id => $roles) {
					foreach ($roles as $role_id => $access) {
						if (empty($access)) continue;

						if ($users_meta_key == "users") {
							//add the ace to the database
							$this->dobj->db_query($this->dobj->insert(array("user_id"=>$user_id, "template_id"=>$template_id, "role"=>$role_id), "report_acls_ldap_users"));
						} else if ($users_meta_key == "groups") {
							//add the ace to the database
							$this->dobj->db_query($this->dobj->insert(array("group_id"=>$user_id, "template_id"=>$template_id, "role"=>$role_id), "report_acls_ldap_groups"));
						}
					}
				}
			}
		}

		return;
	}

	function view_recipient_selector() {
		if (!empty($_REQUEST['data'])) {
			$recipients_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM ldap_recipients WHERE template_id='".$this->id."';"));

			if (!empty($recipients_query)) {
				foreach ($recipients_query as $recipient) {
					$uid = $recipient['uid'];

					$recipients[$uid] = true;
				}
			}

			$recipients_subtraction = $recipients;

			foreach ($_REQUEST['data'] as $key_tmp => $data_tmp) {
				if (substr($key_tmp, 0, 10) != "recipient_") continue;

				$uid = substr($key_tmp, 10);

				//user not in database table: add them
				if (empty($recipients[$uid])) {
					$this->dobj->db_query($this->dobj->insert(array("template_id"=>$this->id, "uid"=>$uid), "ldap_recipients"));

					$recipients[$uid] = true;

				//user already in database table: no change
				} else {
				}

				unset($recipients_subtraction[$uid]);
			}

			if (!empty($recipients_subtraction)) {
				foreach ($recipients_subtraction as $uid => $recipient) {
					//user in database table, but not selected: remove them
					$this->dobj->db_query("DELETE FROM ldap_recipients WHERE template_id='".$this->id."' AND uid='$uid';");
				}
			}

			unset($recipients);

			$this->redirect("tabular/add/".$this->id."/execution");
		}

		$recipients_query = $this->dobj->db_fetch_all($this->dobj->db_query("SELECT * FROM ldap_recipients WHERE template_id='".$this->id."';"));

		if (!empty($recipients_query)) {
			foreach ($recipients_query as $recipient) {
				$uid = $recipient['uid'];

				$recipients[$uid] = true;
			}
		}

		#Connect to the ldap server
		$ds=ldap_connect($this->ldaphost);
		if ($ds) {
			$r = ldap_bind($ds);
			$sr = ldap_search($ds, "o=ieaust", "(&(mail=*)(fullname=*))", array("uid", "fullname", "sn", "mail", "ou"));

			ldap_sort($ds, $sr, "sn");

			$ldap_entries = ldap_get_entries($ds, $sr);

			foreach ($ldap_entries as $entry) {
				if (empty($entry['uid'][0])) continue;
				if (empty($entry['fullname'][0])) continue;
				if (empty($entry['mail'][0])) continue;

				$uid = $entry['uid'][0];
				$name = $entry['fullname'][0];
				$email = strtolower($entry['mail'][0]);

				if ($entry['ou']['count'] > 1) {
					foreach ($entry['ou'] as $unit_index => $unit) {
						if ($unit_index == "count") continue;
						if (empty($unit)) continue;

						$units[$unit] = $unit;

						$unit_entries[$unit][$uid] = $uid;
						$uid_units[$uid][$unit] = $unit;
					}
				} else if ($entry['ou']['count'] > 0 && !empty($entry['ou'][0])) {
					$unit = strtolower($entry['ou'][0]);
					$units[$unit] = $unit;

					$unit_entries[$unit][$uid] = $uid;
					$uid_units[$uid][$unit] = $unit;
				}

				$users[$uid] = array($name, $email, implode(", ", (array)$uid_units[$uid]), !empty($recipients[$uid]));
			}

			ldap_close($ds);
		}

		$output = Ldap_View::view_recipient_selector($users);
		return $output;
	}
}

class Ldap_View {
	function view_recipient_selector($users) {
		$output->title = "LDAP Users";

		$output->data .= $this->f("ldap/recipient_selector/".$this->id, "dojoType='dijit.form.Form'");
		$output->data .= "
			<div class='reports'>
				<table cellpadding='0' cellspacing='0'>
					<tr>
						<th>Name</th>
						<th>Email Address</th>
						<th>Units</th>
						<th>&nbsp;</th>
					</tr>
					";
		foreach ($users as $uid => $user) {
			$output->data .= "
					<tr>
						<td>".$user[0]."</td>
						<td>".$user[1]."</td>
						<td>".$user[2]."</td>
						<td><input name='data[recipient_$uid]' type='checkbox' ".($user[3] ? "checked" : "")." /></td>
					</tr>
					";
		}
		$output->data .= "
				</table>
			</div>
			";

		$output->data .= "
			<div class='input'>
				<button value='Cancel' dojoType='dijit.form.Button' onclick='window.location=\"".$this->webroot()."tabular/add/".$this->id."/execution\"; return false;' name='cancel' />Cancel</button><button type='submit' value='Save' dojoType='dijit.form.Button' name='save' />Save</button>
			</div>
			";
		$output->data .= $this->f_close();

		return $output;
	}
}

?>